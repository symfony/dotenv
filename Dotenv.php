<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Dotenv;

use Symfony\Component\Dotenv\Exception\FormatException;
use Symfony\Component\Dotenv\Exception\FormatExceptionContext;
use Symfony\Component\Dotenv\Exception\PathException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Manages .env files.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class Dotenv
{
    const VARNAME_REGEX = '(?i:[A-Z][A-Z0-9_]*+)';
    const STATE_VARNAME = 0;
    const STATE_VALUE = 1;

    private $path;
    private $cursor;
    private $lineno;
    private $data;
    private $end;
    private $state;
    private $values;

    /**
     * Loads one or several .env files.
     *
     * @param string    $path       A file to load
     * @param ...string $extraPaths A list of additional files to load
     *
     * @throws FormatException when a file has a syntax error
     * @throws PathException   when a file does not exist or is not readable
     */
    public function load(string $path, string ...$extraPaths): void
    {
        $this->doLoad(false, \func_get_args());
    }

    /**
     * Loads a .env file and the corresponding .env.local, .env.$env and .env.$env.local files if they exist.
     *
     * .env.local is always ignored in test env because tests should produce the same results for everyone.
     * .env.dist is loaded when it exists and .env is not found.
     *
     * @param string $path       A file to load
     * @param string $varName    The name of the env vars that defines the app env
     * @param string $defaultEnv The app env to use when none is defined
     * @param array  $testEnvs   A list of app envs for which .env.local should be ignored
     *
     * @throws FormatException when a file has a syntax error
     * @throws PathException   when a file does not exist or is not readable
     */
    public function loadEnv(string $path, string $varName = 'APP_ENV', string $defaultEnv = 'dev', array $testEnvs = ['test']): void
    {
        if (file_exists($path) || !file_exists($p = "$path.dist")) {
            $this->load($path);
        } else {
            $this->load($p);
        }

        if (null === $env = $_SERVER[$varName] ?? $_ENV[$varName] ?? null) {
            $this->populate([$varName => $env = $defaultEnv]);
        }

        if (!\in_array($env, $testEnvs, true) && file_exists($p = "$path.local")) {
            $this->load($p);
            $env = $_SERVER[$varName] ?? $_ENV[$varName] ?? $env;
        }

        if (file_exists($p = "$path.$env")) {
            $this->load($p);
        }

        if (file_exists($p = "$path.$env.local")) {
            $this->load($p);
        }
    }

    /**
     * Loads one or several .env files and enables override existing vars.
     *
     * @param string    $path       A file to load
     * @param ...string $extraPaths A list of additional files to load
     *
     * @throws FormatException when a file has a syntax error
     * @throws PathException   when a file does not exist or is not readable
     */
    public function overload(string $path, string ...$extraPaths): void
    {
        $this->doLoad(true, \func_get_args());
    }

    /**
     * Sets values as environment variables (via putenv, $_ENV, and $_SERVER).
     *
     * @param array $values               An array of env variables
     * @param bool  $overrideExistingVars true when existing environment variables must be overridden
     */
    public function populate(array $values, bool $overrideExistingVars = false): void
    {
        $updateLoadedVars = false;
        $loadedVars = array_flip(explode(',', $_SERVER['SYMFONY_DOTENV_VARS'] ?? $_ENV['SYMFONY_DOTENV_VARS'] ?? ''));

        foreach ($values as $name => $value) {
            $notHttpName = 0 !== strpos($name, 'HTTP_');
            // don't check existence with getenv() because of thread safety issues
            if (!isset($loadedVars[$name]) && (!$overrideExistingVars && (isset($_ENV[$name]) || (isset($_SERVER[$name]) && $notHttpName)))) {
                continue;
            }

            putenv("$name=$value");
            $_ENV[$name] = $value;
            if ($notHttpName) {
                $_SERVER[$name] = $value;
            }

            if (!isset($loadedVars[$name])) {
                $loadedVars[$name] = $updateLoadedVars = true;
            }
        }

        if ($updateLoadedVars) {
            unset($loadedVars['']);
            $loadedVars = implode(',', array_keys($loadedVars));
            putenv('SYMFONY_DOTENV_VARS='.$_ENV['SYMFONY_DOTENV_VARS'] = $_SERVER['SYMFONY_DOTENV_VARS'] = $loadedVars);
        }
    }

    /**
     * Parses the contents of an .env file.
     *
     * @param string $data The data to be parsed
     * @param string $path The original file name where data where stored (used for more meaningful error messages)
     *
     * @return array An array of env variables
     *
     * @throws FormatException when a file has a syntax error
     */
    public function parse(string $data, string $path = '.env'): array
    {
        $this->path = $path;
        $this->data = str_replace(["\r\n", "\r"], "\n", $data);
        $this->lineno = 1;
        $this->cursor = 0;
        $this->end = \strlen($this->data);
        $this->state = self::STATE_VARNAME;
        $this->values = [];
        $name = '';

        $this->skipEmptyLines();

        while ($this->cursor < $this->end) {
            switch ($this->state) {
                case self::STATE_VARNAME:
                    $name = $this->lexVarname();
                    $this->state = self::STATE_VALUE;
                    break;

                case self::STATE_VALUE:
                    $this->values[$name] = $this->lexValue();
                    $this->state = self::STATE_VARNAME;
                    break;
            }
        }

        if (self::STATE_VALUE === $this->state) {
            $this->values[$name] = '';
        }

        try {
            return $this->values;
        } finally {
            $this->values = [];
            $this->data = null;
            $this->path = null;
        }
    }

    private function lexVarname()
    {
        // var name + optional export
        if (!preg_match('/(export[ \t]++)?('.self::VARNAME_REGEX.')/A', $this->data, $matches, 0, $this->cursor)) {
            throw $this->createFormatException('Invalid character in variable name');
        }
        $this->moveCursor($matches[0]);

        if ($this->cursor === $this->end || "\n" === $this->data[$this->cursor] || '#' === $this->data[$this->cursor]) {
            if ($matches[1]) {
                throw $this->createFormatException('Unable to unset an environment variable');
            }

            throw $this->createFormatException('Missing = in the environment variable declaration');
        }

        if (' ' === $this->data[$this->cursor] || "\t" === $this->data[$this->cursor]) {
            throw $this->createFormatException('Whitespace are not supported after the variable name');
        }

        if ('=' !== $this->data[$this->cursor]) {
            throw $this->createFormatException('Missing = in the environment variable declaration');
        }
        ++$this->cursor;

        return $matches[2];
    }

    private function lexValue(): string
    {
        if (preg_match('/[ \t]*+(?:#.*)?$/Am', $this->data, $matches, 0, $this->cursor)) {
            $this->moveCursor($matches[0]);
            $this->skipEmptyLines();

            return '';
        }

        if (' ' === $this->data[$this->cursor] || "\t" === $this->data[$this->cursor]) {
            throw $this->createFormatException('Whitespace are not supported before the value');
        }

        $v = '';

        do {
            if ("'" === $this->data[$this->cursor]) {
                $value = '';
                ++$this->cursor;

                while ("\n" !== $this->data[$this->cursor]) {
                    if ("'" === $this->data[$this->cursor]) {
                        break;
                    }
                    $value .= $this->data[$this->cursor];
                    ++$this->cursor;

                    if ($this->cursor === $this->end) {
                        throw $this->createFormatException('Missing quote to end the value');
                    }
                }
                if ("\n" === $this->data[$this->cursor]) {
                    throw $this->createFormatException('Missing quote to end the value');
                }
                ++$this->cursor;
                $v .= $value;
            } elseif ('"' === $this->data[$this->cursor]) {
                $value = '';
                ++$this->cursor;

                while ('"' !== $this->data[$this->cursor] || ('\\' === $this->data[$this->cursor - 1] && '\\' !== $this->data[$this->cursor - 2])) {
                    $value .= $this->data[$this->cursor];
                    ++$this->cursor;

                    if ($this->cursor === $this->end) {
                        throw $this->createFormatException('Missing quote to end the value');
                    }
                }
                if ("\n" === $this->data[$this->cursor]) {
                    throw $this->createFormatException('Missing quote to end the value');
                }
                ++$this->cursor;
                $value = str_replace(['\\\\', '\\"', '\r', '\n'], ['\\', '"', "\r", "\n"], $value);
                $resolvedValue = $value;
                $resolvedValue = $this->resolveVariables($resolvedValue);
                $resolvedValue = $this->resolveCommands($resolvedValue);
                $v .= $resolvedValue;
            } else {
                $value = '';
                $prevChr = $this->data[$this->cursor - 1];
                while ($this->cursor < $this->end && !\in_array($this->data[$this->cursor], ["\n", '"', "'"], true) && !((' ' === $prevChr || "\t" === $prevChr) && '#' === $this->data[$this->cursor])) {
                    if ('\\' === $this->data[$this->cursor] && isset($this->data[$this->cursor + 1]) && ('"' === $this->data[$this->cursor + 1] || "'" === $this->data[$this->cursor + 1])) {
                        ++$this->cursor;
                    }

                    $value .= $prevChr = $this->data[$this->cursor];

                    if ('$' === $this->data[$this->cursor] && isset($this->data[$this->cursor + 1]) && '(' === $this->data[$this->cursor + 1]) {
                        ++$this->cursor;
                        $value .= '('.$this->lexNestedExpression().')';
                    }

                    ++$this->cursor;
                }
                $value = rtrim($value);
                $resolvedValue = $value;
                $resolvedValue = $this->resolveVariables($resolvedValue);
                $resolvedValue = $this->resolveCommands($resolvedValue);

                if ($resolvedValue === $value && preg_match('/\s+/', $value)) {
                    throw $this->createFormatException('A value containing spaces must be surrounded by quotes');
                }

                $v .= $resolvedValue;

                if ($this->cursor < $this->end && '#' === $this->data[$this->cursor]) {
                    break;
                }
            }
        } while ($this->cursor < $this->end && "\n" !== $this->data[$this->cursor]);

        $this->skipEmptyLines();

        return $v;
    }

    private function lexNestedExpression(): string
    {
        ++$this->cursor;
        $value = '';

        while ("\n" !== $this->data[$this->cursor] && ')' !== $this->data[$this->cursor]) {
            $value .= $this->data[$this->cursor];

            if ('(' === $this->data[$this->cursor]) {
                $value .= $this->lexNestedExpression().')';
            }

            ++$this->cursor;

            if ($this->cursor === $this->end) {
                throw $this->createFormatException('Missing closing parenthesis.');
            }
        }

        if ("\n" === $this->data[$this->cursor]) {
            throw $this->createFormatException('Missing closing parenthesis.');
        }

        return $value;
    }

    private function skipEmptyLines(): void
    {
        if (preg_match('/(?:\s*+(?:#[^\n]*+)?+)++/A', $this->data, $match, 0, $this->cursor)) {
            $this->moveCursor($match[0]);
        }
    }

    private function resolveCommands($value)
    {
        if (false === strpos($value, '$')) {
            return $value;
        }

        $regex = '/
            (\\\\)?               # escaped with a backslash?
            \$
            (?<cmd>
                \(                # require opening parenthesis
                ([^()]|\g<cmd>)+  # allow any number of non-parens, or balanced parens (by nesting the <cmd> expression recursively)
                \)                # require closing paren
            )
        /x';

        return preg_replace_callback($regex, function ($matches) {
            if ('\\' === $matches[1]) {
                return substr($matches[0], 1);
            }

            if ('\\' === \DIRECTORY_SEPARATOR) {
                throw new \LogicException('Resolving commands is not supported on Windows.');
            }

            if (!class_exists(Process::class)) {
                throw new \LogicException('Resolving commands requires the Symfony Process component.');
            }

            $process = \method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('echo '.$matches[0]) : new Process('echo '.$matches[0]);
            $process->inheritEnvironmentVariables(true);
            $process->setEnv($this->values);
            try {
                $process->mustRun();
            } catch (ProcessException $e) {
                throw $this->createFormatException(sprintf('Issue expanding a command (%s)', $process->getErrorOutput()));
            }

            return preg_replace('/[\r\n]+$/', '', $process->getOutput());
        }, $value);
    }

    private function resolveVariables($value)
    {
        if (false === strpos($value, '$')) {
            return $value;
        }

        $regex = '/
            (\\\\)?                    # escaped with a backslash?
            \$
            (?!\()                     # no opening parenthesis
            (\{)?                      # optional brace
            ('.self::VARNAME_REGEX.')  # var name
            (\})?                      # optional closing brace
        /x';

        $value = preg_replace_callback($regex, function ($matches) {
            if ('\\' === $matches[1]) {
                return substr($matches[0], 1);
            }

            if ('{' === $matches[2] && !isset($matches[4])) {
                throw $this->createFormatException('Unclosed braces on variable expansion');
            }

            $name = $matches[3];
            if (isset($this->values[$name])) {
                $value = $this->values[$name];
            } elseif (isset($_SERVER[$name]) && 0 !== strpos($name, 'HTTP_')) {
                $value = $_SERVER[$name];
            } elseif (isset($_ENV[$name])) {
                $value = $_ENV[$name];
            } else {
                $value = (string) getenv($name);
            }

            if (!$matches[2] && isset($matches[4])) {
                $value .= '}';
            }

            return $value;
        }, $value);

        // unescape $
        return str_replace('\\$', '$', $value);
    }

    private function moveCursor(string $text): void
    {
        $this->cursor += \strlen($text);
        $this->lineno += substr_count($text, "\n");
    }

    private function createFormatException($message): FormatException
    {
        return new FormatException($message, new FormatExceptionContext($this->data, $this->path, $this->lineno, $this->cursor));
    }

    private function doLoad(bool $overrideExistingVars, array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_readable($path) || is_dir($path)) {
                throw new PathException($path);
            }

            $this->populate($this->parse(file_get_contents($path), $path), $overrideExistingVars);
        }
    }
}
