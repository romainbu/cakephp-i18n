<?php
declare(strict_types=1);

namespace ADmad\I18n\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Filesystem\Filesystem;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;

/**
 * Language string extractor
 */
class I18nExtractCommand extends \Cake\Command\I18nExtractCommand
{
    use I18nModelTrait;

    /**
     * Default model for storing translation messages.
     */
    public const DEFAULT_MODEL = 'I18nMessages';

    /**
     * App languages.
     *
     * @var array
     */
    protected $_languages = [];

    /**
     * The name of this command.
     *
     * @var string
     */
    protected $name = 'admad/i18n extract';

    /**
     * Get the command name.
     *
     * Returns the command name based on class name.
     * For e.g. for a command with class name `UpdateTableCommand` the default
     * name returned would be `'update_table'`.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'admad/i18n extract';
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $plugin = '';
        if ($args->getOption('exclude')) {
            $this->_exclude = explode(',', (string)$args->getOption('exclude'));
        }
        if ($args->getOption('files')) {
            $this->_files = explode(',', (string)$args->getOption('files'));
        }
        if ($args->getOption('paths')) {
            $this->_paths = explode(',', (string)$args->getOption('paths'));
        } elseif ($args->getOption('plugin')) {
            $plugin = Inflector::camelize((string)$args->getOption('plugin'));
            $this->_paths = [Plugin::classPath($plugin), Plugin::templatePath($plugin)];
        } else {
            $this->_getPaths($io);
        }

        if ($args->hasOption('extract-core')) {
            $this->_extractCore = !(strtolower((string)$args->getOption('extract-core')) === 'no');
        } else {
            $response = $io->askChoice(
                'Would you like to extract the messages from the CakePHP core?',
                ['y', 'n'],
                'n'
            );
            $this->_extractCore = strtolower((string)$response) === 'y';
        }

        if ($args->hasOption('exclude-plugins') && $this->_isExtractingApp()) {
            $this->_exclude = array_merge($this->_exclude, App::path('plugins'));
        }

        if ($this->_extractCore) {
            $this->_paths[] = CAKE;
        }

        if ($args->hasOption('merge')) {
            $this->_merge = !(strtolower((string)$args->getOption('merge')) === 'no');
        } else {
            $io->out();
            $response = $io->askChoice(
                'Would you like to merge all domain strings into the default.pot file?',
                ['y', 'n'],
                'n'
            );
            $this->_merge = strtolower((string)$response) === 'y';
        }

        $this->_markerError = (bool)$args->getOption('marker-error');
        $this->_relativePaths = (bool)$args->getOption('relative-paths');

        if (empty($this->_files)) {
            $this->_searchFiles();
        }

        $this->_extract($args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Extract text
     *
     * @param \Cake\Console\Arguments $args The Arguments instance
     * @param \Cake\Console\ConsoleIo $io The io instance
     * @return void
     */
    protected function _extract(Arguments $args, ConsoleIo $io): void
    {
        $io->out();
        $io->out();
        $io->out('Extracting...');
        $io->hr();
        $io->out('Paths:');
        foreach ($this->_paths as $path) {
            $io->out('   ' . $path);
        }
        $io->hr();
        $this->_extractTokens($args, $io);

        $this->_getLanguages($args);
        $this->_saveMessages($args, $io);

        $this->_paths = $this->_files = $this->_storage = [];
        $this->_translations = $this->_tokens = [];
        $io->out();
        if ($this->_countMarkerError) {
            $io->err("{$this->_countMarkerError} marker error(s) detected.");
            $io->err(" => Use the --marker-error option to display errors.");
        }

        $io->out('Done.');
    }

    /**
     * Get app languages.
     *
     * @param \Cake\Console\Arguments $args The Arguments instance
     * @return void
     */
    protected function _getLanguages(Arguments $args): void
    {
        $langs = (string)$args->getOption('languages');
        if ($langs) {
            $this->_languages = explode(',', $langs);

            return;
        }

        $langs = Configure::read('I18n.languages');
        if (empty($langs)) {
            return;
        }

        $langs = Hash::normalize($langs);
        foreach ($langs as $key => $value) {
            if (isset($value['locale'])) {
                $this->_languages[] = $value['locale'];
            } else {
                $this->_languages[] = $key;
            }
        }
    }

    /**
     * Save translation messages to repository.
     *
     * @param \Cake\Console\Arguments $args The Arguments instance
     * @param \Cake\Console\ConsoleIo $io The io instance
     * @return void
     */
    protected function _saveMessages(Arguments $args, ConsoleIo $io): void
    {
        $paths = $this->_paths;
        /** @psalm-suppress UndefinedConstant */
        $paths[] = realpath(APP) . DIRECTORY_SEPARATOR;

        usort($paths, function (string $a, string $b): int {
            return strlen($a) - strlen($b);
        });

        $domains = null;
        if ($args->getOption('domains')) {
            $domains = explode(',', (string)$args->getOption('domains'));
        }

        $this->_loadModel($args);

        foreach ($this->_translations as $domain => $translations) {
            if (!empty($domains) && !in_array($domain, $domains)) {
                continue;
            }
            if ($this->_merge) {
                $domain = 'default';
            }
            foreach ($translations as $msgid => $contexts) {
                foreach ($contexts as $context => $details) {
                    $references = null;
                    if (!$args->getOption('no-location')) {
                        $files = $details['references'];
                        $occurrences = [];
                        foreach ($files as $file => $lines) {
                            $lines = array_unique($lines);
                            $occurrences[] = $file . ':' . implode(';', $lines);
                        }
                        $occurrences = implode("\n", $occurrences);
                        $occurrences = str_replace($paths, '', $occurrences);
                        $references = str_replace(DIRECTORY_SEPARATOR, '/', $occurrences);
                    }

                    $this->_save(
                        $domain,
                        $msgid,
                        $details['msgid_plural'] === false ? null : $details['msgid_plural'],
                        $context ?: null,
                        $references
                    );
                }
            }
        }
    }

    /**
     * Save translation record to repository.
     *
     * @param string $domain Domain name
     * @param string $singular Singular message id.
     * @param string|null $plural Plural message id.
     * @param string|null $context Context.
     * @param string|null $refs Source code references.
     * @return void
     */
    protected function _save(
        string $domain,
        string $singular,
        ?string $plural = null,
        ?string $context = null,
        ?string $refs = null
    ): void {
        foreach ($this->_languages as $locale) {
            $found = $this->_model->find()
                ->where(compact('domain', 'locale', 'singular'))
                ->count();

            if (!$found) {
                $entity = $this->_model->newEntity(compact(
                    'domain',
                    'locale',
                    'singular',
                    'plural',
                    'context',
                    'refs'
                ), ['guard' => false]);

                $this->_model->save($entity);
            }
        }
    }

    /**
     * Gets the option parser instance and configures it.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to configure
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription(
            'Extract translated strings from application source files. ' .
            'Source files are parsed and string literal format strings ' .
            'provided to the <info>__</info> family of functions are extracted.'
        )->addOption('model', [
            'help' => 'Model to use for storing messages. Defaults to: ' . static::DEFAULT_MODEL,
        ])->addOption('languages', [
            'help' => 'Comma separated list of languages used by app. Defaults used from `I18n.languages` config.',
        ])->addOption('app', [
            'help' => 'Directory where your application is located.',
        ])->addOption('paths', [
            'help' => 'Comma separated list of paths that are searched for source files.',
        ])->addOption('merge', [
            'help' => 'Merge all domain strings into a single `default` domain.',
            'default' => 'no',
            'choices' => ['yes', 'no'],
        ])->addOption('relative-paths', [
            'help' => 'Use application relative paths in references.',
            'boolean' => true,
            'default' => false,
        ])->addOption('files', [
            'help' => 'Comma separated list of files to parse.',
        ])->addOption('exclude-plugins', [
            'boolean' => true,
            'default' => true,
            'help' => 'Ignores all files in plugins if this command is run inside from the same app directory.',
        ])->addOption('plugin', [
            'help' => 'Extracts tokens only from the plugin specified and '
                . 'puts the result in the plugin\'s Locale directory.',
        ])->addOption('exclude', [
            'help' => 'Comma separated list of directories to exclude.' .
                ' Any path containing a path segment with the provided values will be skipped. E.g. test,vendors',
        ])->addOption('extract-core', [
            'help' => 'Extract messages from the CakePHP core libraries.',
            'choices' => ['yes', 'no'],
        ])->addOption('no-location', [
            'boolean' => true,
            'default' => false,
            'help' => 'Do not write file locations for each extracted message.',
        ])->addOption('marker-error', [
            'boolean' => true,
            'default' => false,
            'help' => 'Do not display marker error.',
        ]);

        return $parser;
    }

    /**
     * Extract tokens out of all files to be processed
     *
     * @param \Cake\Console\Arguments $args The io instance
     * @param \Cake\Console\ConsoleIo $io The io instance
     * @return void
     */
    protected function _extractTokens(Arguments $args, ConsoleIo $io): void
    {
        /** @var \Cake\Shell\Helper\ProgressHelper $progress */
        $progress = $io->helper('progress');
        $progress->init(['total' => count($this->_files)]);
        $isVerbose = $args->getOption('verbose');

        $functions = [
            '__' => ['singular'],
            '__n' => ['singular', 'plural'],
            '__d' => ['domain', 'singular'],
            '__dn' => ['domain', 'singular', 'plural'],
            '__x' => ['context', 'singular'],
            '__xn' => ['context', 'singular', 'plural'],
            '__dx' => ['domain', 'context', 'singular'],
            '__dxn' => ['domain', 'context', 'singular', 'plural'],
        ];
        $pattern = '/(' . implode('|', array_keys($functions)) . ')\s*\(/';

        foreach ($this->_files as $file) {
            $this->_file = $file;
            if ($isVerbose) {
                $io->verbose(sprintf('Processing %s...', $file));
            }

            $code = file_get_contents($file);

            if (preg_match($pattern, $code) === 1) {
                $allTokens = token_get_all($code);

                $this->_tokens = [];
                foreach ($allTokens as $token) {
                    if (!is_array($token) || ($token[0] !== T_WHITESPACE && $token[0] !== T_INLINE_HTML)) {
                        $this->_tokens[] = $token;
                    }
                }
                unset($allTokens);

                foreach ($functions as $functionName => $map) {
                    $this->_parse($io, $functionName, $map);
                }
            }

            if (!$isVerbose) {
                $progress->increment(1);
                $progress->draw();
            }
        }
    }

    /**
     * Parse tokens
     *
     * @param \Cake\Console\ConsoleIo $io The io instance
     * @param string $functionName Function name that indicates translatable string (e.g: '__')
     * @param array $map Array containing what variables it will find (e.g: domain, singular, plural)
     * @return void
     */
    protected function _parse(ConsoleIo $io, string $functionName, array $map): void
    {
        $count = 0;
        $tokenCount = count($this->_tokens);

        while ($tokenCount - $count > 1) {
            $countToken = $this->_tokens[$count];
            $firstParenthesis = $this->_tokens[$count + 1];
            if (!is_array($countToken)) {
                $count++;
                continue;
            }

            [$type, $string, $line] = $countToken;
            if (($type === T_STRING) && ($string === $functionName) && ($firstParenthesis === '(')) {
                $position = $count;
                $depth = 0;

                while (!$depth) {
                    if ($this->_tokens[$position] === '(') {
                        $depth++;
                    } elseif ($this->_tokens[$position] === ')') {
                        $depth--;
                    }
                    $position++;
                }

                $mapCount = count($map);
                $strings = $this->_getStrings($position, $mapCount);

                if ($mapCount === count($strings)) {
                    $singular = '';
                    $plural = $context = null;
                    extract(array_combine($map, $strings));
                    $domain = $domain ?? 'default';
                    $details = [
                        'file' => $this->_file,
                        'line' => $line,
                    ];
                    if ($this->_relativePaths) {
                        $details['file'] = '.' . str_replace(ROOT, '', $details['file']);
                    }
                    if ($plural !== null) {
                        $details['msgid_plural'] = $plural;
                    }
                    if ($context !== null) {
                        $details['msgctxt'] = $context;
                    }
                    $this->_addTranslation($domain, $singular, $details);
                } else {
                    $this->_markerError($io, $this->_file, $line, $functionName, $count);
                }
            }
            $count++;
        }
    }

    /**
     * Get the strings from the position forward
     *
     * @param int $position Actual position on tokens array
     * @param int $target Number of strings to extract
     * @return array Strings extracted
     */
    protected function _getStrings(int &$position, int $target): array
    {
        $strings = [];
        $count = count($strings);
        while (
            $count < $target
            && ($this->_tokens[$position] === ','
                || $this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING
                || $this->_tokens[$position][0] === T_LNUMBER
            )
        ) {
            $count = count($strings);
            if ($this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING && $this->_tokens[$position + 1] === '.') {
                $string = '';
                while (
                    $this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING
                    || $this->_tokens[$position] === '.'
                ) {
                    if ($this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $string .= $this->_formatString($this->_tokens[$position][1]);
                    }
                    $position++;
                }
                $strings[] = $string;
            } elseif ($this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = $this->_formatString($this->_tokens[$position][1]);
            } elseif ($this->_tokens[$position][0] === T_LNUMBER) {
                $strings[] = $this->_tokens[$position][1];
            }
            $position++;
        }

        return $strings;
    }

    /**
     * Format a string to be added as a translatable string
     *
     * @param string $string String to format
     * @return string Formatted string
     */
    protected function _formatString(string $string): string
    {
        $quote = substr($string, 0, 1);
        $string = substr($string, 1, -1);
        if ($quote === '"') {
            $string = stripcslashes($string);
        } else {
            $string = strtr($string, ["\\'" => "'", '\\\\' => '\\']);
        }
        $string = str_replace("\r\n", "\n", $string);

        return addcslashes($string, "\0..\37\\\"");
    }

    /**
     * Indicate an invalid marker on a processed file
     *
     * @param \Cake\Console\ConsoleIo $io The io instance.
     * @param string $file File where invalid marker resides
     * @param int $line Line number
     * @param string $marker Marker found
     * @param int $count Count
     * @return void
     */
    protected function _markerError($io, string $file, int $line, string $marker, int $count): void
    {
        if (strpos($this->_file, CAKE_CORE_INCLUDE_PATH) === false) {
            $this->_countMarkerError++;
        }

        if (!$this->_markerError) {
            return;
        }

        $io->err(sprintf("Invalid marker content in %s:%s\n* %s(", $file, $line, $marker));
        $count += 2;
        $tokenCount = count($this->_tokens);
        $parenthesis = 1;

        while (($tokenCount - $count > 0) && $parenthesis) {
            if (is_array($this->_tokens[$count])) {
                $io->err($this->_tokens[$count][1], 0);
            } else {
                $io->err($this->_tokens[$count], 0);
                if ($this->_tokens[$count] === '(') {
                    $parenthesis++;
                }

                if ($this->_tokens[$count] === ')') {
                    $parenthesis--;
                }
            }
            $count++;
        }
        $io->err("\n");
    }

    /**
     * Search files that may contain translatable strings
     *
     * @return void
     */
    protected function _searchFiles(): void
    {
        $pattern = false;
        if (!empty($this->_exclude)) {
            $exclude = [];
            foreach ($this->_exclude as $e) {
                if (DIRECTORY_SEPARATOR !== '\\' && $e[0] !== DIRECTORY_SEPARATOR) {
                    $e = DIRECTORY_SEPARATOR . $e;
                }
                $exclude[] = preg_quote($e, '/');
            }
            $pattern = '/' . implode('|', $exclude) . '/';
        }

        foreach ($this->_paths as $path) {
            $path = realpath($path) . DIRECTORY_SEPARATOR;
            $fs = new Filesystem();
            $files = $fs->findRecursive($path, '/\.php$/');
            $files = array_keys(iterator_to_array($files));
            sort($files);
            if (!empty($pattern)) {
                $files = preg_grep($pattern, $files, PREG_GREP_INVERT);
                $files = array_values($files);
            }
            $this->_files = array_merge($this->_files, $files);
        }
        $this->_files = array_unique($this->_files);
    }

    /**
     * Returns whether this execution is meant to extract string only from directories in folder represented by the
     * APP constant, i.e. this task is extracting strings from same application.
     *
     * @return bool
     */
    protected function _isExtractingApp(): bool
    {
        /** @psalm-suppress UndefinedConstant */
        return $this->_paths === [APP];
    }

    /**
     * Checks whether or not a given path is usable for writing.
     *
     * @param string $path Path to folder
     * @return bool true if it exists and is writable, false otherwise
     */
    protected function _isPathUsable($path): bool
    {
        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return is_dir($path) && is_writable($path);
    }
}
