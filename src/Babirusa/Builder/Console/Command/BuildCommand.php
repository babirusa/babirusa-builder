<?php

namespace Babirusa\Builder\Console\Command;

use CallbackFilterIterator;
use Generator;
use Github\Client;
use Github\Exception\RuntimeException;
use MP\Docker\BuildContext;
use MP\Docker\DockerBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class BuildCommand extends Command
{
    protected $dynamicOptions = [];

    private $github;

    /**
     * {@inheritdoc}
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->github = new Client();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('build')
            ->addOption('platform', 'p', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Build only specific platforms')
            ->addOption('temp', 't', InputOption::VALUE_OPTIONAL, 'Use specific location as a temporary directory')
            ->addOption('upload', 'u', InputOption::VALUE_OPTIONAL, 'Upload builds to github repository')
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'GitHub repository to which build should be uploaded', 'babirusa/babirusa-runtime')
            ->addArgument('versions', InputArgument::IS_ARRAY, 'PHP Versions which should be build');

        $this->setDefinition(new class($this->getDefinition(), $this->dynamicOptions) extends InputDefinition
        {
            protected $dynamicOptions = [];

            public function __construct(InputDefinition $definition, array &$dynamicOptions)
            {
                parent::__construct();
                $this->setArguments($definition->getArguments());
                $this->setOptions($definition->getOptions());
                $this->dynamicOptions =& $dynamicOptions;
            }

            public function getOption($name)
            {
                if (!parent::hasOption($name)) {
                    $this->addOption(new InputOption($name, null, InputOption::VALUE_OPTIONAL));
                    $this->dynamicOptions[] = $name;
                }
                return parent::getOption($name);
            }

            public function hasOption($name)
            {
                return true;
            }
        });
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Github\Exception\MissingArgumentException
     * @throws \Github\Exception\ErrorException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->hasOption('upload')) {
            $helper = $this->getHelper('question');
            $question = new Question('Github token: ');
            $token = $helper->ask($input, $output, $question);

            $this->github->authenticate($token, null, Client::AUTH_HTTP_TOKEN);
        }

        foreach ($this->listContainers($input->getOption('platform')) as $container) {
            foreach ($input->getArgument('versions') as $version) {
                $io->section(sprintf('Building PHP version %s for %s platform', $version, $container->platform));

                $tag = sprintf('php-%s-v%', $container->platform, $version);
                $docker = new DockerBuilder($container);
                $image = $docker->build($tag, '-t', $tag, '-t', $tag, '--build-arg', 'PHP_VERSION=php-'.$version);
                try {
                    $image->start();
                    $destination = 'php-' . $version;
                    try {
                        $image->extract('/php-src-php-' . $version . '/sapi/cli/php', $destination);
                        $this->createRelease($output, $input->getOption('repository'), $image->getTag(), $destination);
                    } finally {
                        @unlink($destination);
                    }

                } finally {
                    $image->destroy();
                }
            }
        }
    }

    /**
     * @param ConsoleOutputInterface $output
     * @param                        $repository
     * @param                        $tag
     * @param                        $build
     * @throws \Github\Exception\ErrorException
     * @throws \Github\Exception\MissingArgumentException
     */
    protected function createRelease(ConsoleOutputInterface $output, $repository, $tag, $build)
    {
        list($username, $repository) = explode('/', $repository);
        $api = $this->github->repository()->releases();

        try {
            $release = $api->tag($username, $repository, $tag);
        } catch (RuntimeException $e) {
            $release = $api->create($username, $repository, ['tag_name' => $tag]);
        }

        try {
            $output->writeln('Uploading asset to GitHub');

            $assets = array_filter($release['assets'], function ($release) {
                return $release['name'] == 'php';
            });

            foreach ($assets as $asset) {
                try {
                    $api->assets()->remove($username, $repository, $asset['id']);
                } catch (\Exception $e) {
                    $output->getErrorOutput()->writeln('Filed to remove asset');
                }
            }

            $api->assets()->create(
                $username, $repository, $release['id'], 'php', 'application/octet-stream', file_get_contents($build)
            );
        } catch (RuntimeException $e) {
            $output->getErrorOutput()->writeln($e->getMessage());
        }
    }

    /**
     * @param array $allowed
     * @return Generator
     */
    protected function listContainers($allowed = []): Generator
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getcwd()));
        $it = new CallbackFilterIterator($it, function (splFileInfo $current, $key, $value) {
            return $current->isFile() && $current->getFilename() == 'Dockerfile';
        });

        foreach ($it as $file) {
            $platform = @end(explode(DIRECTORY_SEPARATOR, $file->getPath()));

            if (isset($allowed[0]) && !in_array($platform, $allowed)) {
                continue;
            }

            yield new BuildContext($platform, $file);
        }
    }
}
