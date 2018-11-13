<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Dotenv\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;

class DotenvTest extends TestCase
{
    /**
     * @dataProvider getEnvDataWithFormatErrors
     */
    public function testParseWithFormatError($data, $error)
    {
        $dotenv = new Dotenv();

        try {
            $dotenv->parse($data);
            $this->fail('Should throw a FormatException');
        } catch (FormatException $e) {
            $this->assertStringMatchesFormat($error, $e->getMessage());
        }
    }

    public function getEnvDataWithFormatErrors(): array
    {
        $tests = [
            ['FOO=BAR BAZ', "A value containing spaces must be surrounded by quotes in \".env\" at line 1.\n...FOO=BAR BAZ...\n             ^ line 1 offset 11"],
            ['FOO BAR=BAR', "Whitespace are not supported after the variable name in \".env\" at line 1.\n...FOO BAR=BAR...\n     ^ line 1 offset 3"],
            ['FOO', "Missing = in the environment variable declaration in \".env\" at line 1.\n...FOO...\n     ^ line 1 offset 3"],
            ['FOO="foo', "Missing quote to end the value in \".env\" at line 1.\n...FOO=\"foo...\n          ^ line 1 offset 8"],
            ['FOO=\'foo', "Missing quote to end the value in \".env\" at line 1.\n...FOO='foo...\n          ^ line 1 offset 8"],
            ['export FOO', "Unable to unset an environment variable in \".env\" at line 1.\n...export FOO...\n            ^ line 1 offset 10"],
            ['FOO=${FOO', "Unclosed braces on variable expansion in \".env\" at line 1.\n...FOO=\${FOO...\n           ^ line 1 offset 9"],
        ];

        if ('\\' !== \DIRECTORY_SEPARATOR) {
            $tests[] = ['FOO=$((1dd2))', "Issue expanding a command (%s\n) in \".env\" at line 1.\n...FOO=$((1dd2))...\n               ^ line 1 offset 13"];
        }

        return $tests;
    }

    /**
     * @dataProvider getEnvData
     */
    public function testParse($data, $expected): void
    {
        $dotenv = new Dotenv();
        $this->assertSame($expected, $dotenv->parse($data));
    }

    public function getEnvData(): array
    {
        putenv('LOCAL=local');
        $_ENV['REMOTE'] = 'remote';

        $tests = [
            // spaces
            ['FOO=bar', ['FOO' => 'bar']],
            [' FOO=bar ', ['FOO' => 'bar']],
            ['FOO=', ['FOO' => '']],
            ["FOO=\n\n\nBAR=bar", ['FOO' => '', 'BAR' => 'bar']],
            ['FOO=  ', ['FOO' => '']],
            ["FOO=\nBAR=bar", ['FOO' => '', 'BAR' => 'bar']],

            // newlines
            ["\n\nFOO=bar\r\n\n", ['FOO' => 'bar']],
            ["FOO=bar\r\nBAR=foo", ['FOO' => 'bar', 'BAR' => 'foo']],
            ["FOO=bar\rBAR=foo", ['FOO' => 'bar', 'BAR' => 'foo']],
            ["FOO=bar\nBAR=foo", ['FOO' => 'bar', 'BAR' => 'foo']],

            // quotes
            ["FOO=\"bar\"\n", ['FOO' => 'bar']],
            ["FOO=\"bar'foo\"\n", ['FOO' => 'bar\'foo']],
            ["FOO='bar'\n", ['FOO' => 'bar']],
            ["FOO='bar\"foo'\n", ['FOO' => 'bar"foo']],
            ["FOO=\"bar\\\"foo\"\n", ['FOO' => 'bar"foo']],
            ['FOO="bar\nfoo"', ['FOO' => "bar\nfoo"]],
            ['FOO="bar\rfoo"', ['FOO' => "bar\rfoo"]],
            ['FOO=\'bar\nfoo\'', ['FOO' => 'bar\nfoo']],
            ['FOO=\'bar\rfoo\'', ['FOO' => 'bar\rfoo']],
            ['FOO=" FOO "', ['FOO' => ' FOO ']],
            ['FOO="  "', ['FOO' => '  ']],
            ['PATH="c:\\\\"', ['PATH' => 'c:\\']],
            ["FOO=\"bar\nfoo\"", ['FOO' => "bar\nfoo"]],
            ['FOO=BAR\\"', ['FOO' => 'BAR"']],
            ["FOO=BAR\\'BAZ", ['FOO' => "BAR'BAZ"]],
            ['FOO=\\"BAR', ['FOO' => '"BAR']],

            // concatenated values
            ["FOO='bar''foo'\n", ['FOO' => 'barfoo']],
            ["FOO='bar '' baz'", ['FOO' => 'bar  baz']],
            ["FOO=bar\nBAR='baz'\"\$FOO\"", ['FOO' => 'bar', 'BAR' => 'bazbar']],
            ["FOO='bar '\\'' baz'", ['FOO' => "bar ' baz"]],

            // comments
            ["#FOO=bar\nBAR=foo", ['BAR' => 'foo']],
            ["#FOO=bar # Comment\nBAR=foo", ['BAR' => 'foo']],
            ["FOO='bar foo' # Comment", ['FOO' => 'bar foo']],
            ["FOO='bar#foo' # Comment", ['FOO' => 'bar#foo']],
            ["# Comment\r\nFOO=bar\n# Comment\nBAR=foo", ['FOO' => 'bar', 'BAR' => 'foo']],
            ["FOO=bar # Another comment\nBAR=foo", ['FOO' => 'bar', 'BAR' => 'foo']],
            ["FOO=\n\n# comment\nBAR=bar", ['FOO' => '', 'BAR' => 'bar']],
            ['FOO=NOT#COMMENT', ['FOO' => 'NOT#COMMENT']],
            ['FOO=  # Comment', ['FOO' => '']],

            // edge cases (no conversions, only strings as values)
            ['FOO=0', ['FOO' => '0']],
            ['FOO=false', ['FOO' => 'false']],
            ['FOO=null', ['FOO' => 'null']],

            // export
            ['export FOO=bar', ['FOO' => 'bar']],
            ['  export   FOO=bar', ['FOO' => 'bar']],

            // variable expansion
            ["FOO=BAR\nBAR=\$FOO", ['FOO' => 'BAR', 'BAR' => 'BAR']],
            ["FOO=BAR\nBAR=\"\$FOO\"", ['FOO' => 'BAR', 'BAR' => 'BAR']],
            ["FOO=BAR\nBAR='\$FOO'", ['FOO' => 'BAR', 'BAR' => '$FOO']],
            ["FOO_BAR9=BAR\nBAR=\$FOO_BAR9", ['FOO_BAR9' => 'BAR', 'BAR' => 'BAR']],
            ["FOO=BAR\nBAR=\${FOO}Z", ['FOO' => 'BAR', 'BAR' => 'BARZ']],
            ["FOO=BAR\nBAR=\$FOO}", ['FOO' => 'BAR', 'BAR' => 'BAR}']],
            ["FOO=BAR\nBAR=\\\$FOO", ['FOO' => 'BAR', 'BAR' => '$FOO']],
            ['FOO=" \\$ "', ['FOO' => ' $ ']],
            ['FOO=" $ "', ['FOO' => ' $ ']],
            ['BAR=$LOCAL', ['BAR' => 'local']],
            ['BAR=$REMOTE', ['BAR' => 'remote']],
            ['FOO=$NOTDEFINED', ['FOO' => '']],
        ];

        if ('\\' !== \DIRECTORY_SEPARATOR) {
            $tests = array_merge($tests, [
                // command expansion
                ['FOO=$(echo foo)', ['FOO' => 'foo']],
                ['FOO=$((1+2))', ['FOO' => '3']],
                ['FOO=FOO$((1+2))BAR', ['FOO' => 'FOO3BAR']],
                ['FOO=$(echo "$(echo "$(echo "$(echo foo)")")")', ['FOO' => 'foo']],
                ["FOO=$(echo \"Quotes won't be a problem\")", ['FOO' => 'Quotes won\'t be a problem']],
                ["FOO=bar\nBAR=$(echo \"FOO is \$FOO\")", ['FOO' => 'bar', 'BAR' => 'FOO is bar']],
            ]);
        }

        return $tests;
    }

    public function testLoad(): void
    {
        unset($_ENV['FOO']);
        unset($_ENV['BAR']);
        unset($_SERVER['FOO']);
        unset($_SERVER['BAR']);
        putenv('FOO');
        putenv('BAR');

        @mkdir($tmpdir = sys_get_temp_dir().'/dotenv');

        $path1 = tempnam($tmpdir, 'sf-');
        $path2 = tempnam($tmpdir, 'sf-');

        file_put_contents($path1, 'FOO=BAR');
        file_put_contents($path2, 'BAR=BAZ');

        (new Dotenv())->load($path1, $path2);

        $foo = getenv('FOO');
        $bar = getenv('BAR');

        putenv('FOO');
        putenv('BAR');
        unlink($path1);
        unlink($path2);
        rmdir($tmpdir);

        $this->assertSame('BAR', $foo);
        $this->assertSame('BAZ', $bar);
    }

    public function testLoadEnv(): void
    {
        unset($_ENV['FOO']);
        unset($_ENV['BAR']);
        unset($_SERVER['FOO']);
        unset($_SERVER['BAR']);
        putenv('FOO');
        putenv('BAR');

        @mkdir($tmpdir = sys_get_temp_dir().'/dotenv');

        $path = tempnam($tmpdir, 'sf-');

        // .env

        file_put_contents($path, 'FOO=BAR');
        (new DotEnv())->loadEnv($path, 'TEST_APP_ENV');
        $this->assertSame('BAR', getenv('FOO'));
        $this->assertSame('dev', getenv('TEST_APP_ENV'));

        // .env.local

        file_put_contents("$path.local", 'FOO=localBAR');
        (new DotEnv())->loadEnv($path, 'TEST_APP_ENV');
        $this->assertSame('localBAR', getenv('FOO'));

        // special case for test

        $_SERVER['TEST_APP_ENV'] = 'test';
        (new DotEnv())->loadEnv($path, 'TEST_APP_ENV');
        $this->assertSame('BAR', getenv('FOO'));

        // .env.dev

        unset($_SERVER['TEST_APP_ENV']);
        file_put_contents("$path.dev", 'FOO=devBAR');
        (new DotEnv())->loadEnv($path, 'TEST_APP_ENV');
        $this->assertSame('devBAR', getenv('FOO'));

        // .env.dev.local

        file_put_contents("$path.dev.local", 'FOO=devlocalBAR');
        (new DotEnv())->loadEnv($path, 'TEST_APP_ENV');
        $this->assertSame('devlocalBAR', getenv('FOO'));

        // .env.dist

        unlink($path);
        file_put_contents("$path.dist", 'BAR=distBAR');
        (new DotEnv())->loadEnv($path, 'TEST_APP_ENV');
        $this->assertSame('distBAR', getenv('BAR'));

        putenv('FOO');
        putenv('BAR');
        unlink("$path.dist");
        unlink("$path.local");
        unlink("$path.dev");
        unlink("$path.dev.local");
        rmdir($tmpdir);
    }

    public function testOverload(): void
    {
        unset($_ENV['FOO']);
        unset($_ENV['BAR']);
        unset($_SERVER['FOO']);
        unset($_SERVER['BAR']);

        putenv('FOO=initial_foo_value');
        putenv('BAR=initial_bar_value');
        $_ENV['FOO'] = 'initial_foo_value';
        $_ENV['BAR'] = 'initial_bar_value';

        @mkdir($tmpdir = sys_get_temp_dir().'/dotenv');

        $path1 = tempnam($tmpdir, 'sf-');
        $path2 = tempnam($tmpdir, 'sf-');

        file_put_contents($path1, 'FOO=BAR');
        file_put_contents($path2, 'BAR=BAZ');

        (new Dotenv())->overload($path1, $path2);

        $foo = getenv('FOO');
        $bar = getenv('BAR');

        putenv('FOO');
        putenv('BAR');
        unlink($path1);
        unlink($path2);
        rmdir($tmpdir);

        $this->assertSame('BAR', $foo);
        $this->assertSame('BAZ', $bar);
    }

    /**
     * @expectedException \Symfony\Component\Dotenv\Exception\PathException
     */
    public function testLoadDirectory(): void
    {
        $dotenv = new Dotenv();
        $dotenv->load(__DIR__);
    }

    public function testServerSuperglobalIsNotOverriden(): void
    {
        $originalValue = $_SERVER['argc'];

        $dotenv = new Dotenv();
        $dotenv->populate(['argc' => 'new_value']);

        $this->assertSame($originalValue, $_SERVER['argc']);
    }

    public function testEnvVarIsNotOverriden(): void
    {
        putenv('TEST_ENV_VAR=original_value');
        $_SERVER['TEST_ENV_VAR'] = 'original_value';

        $dotenv = new Dotenv();
        $dotenv->populate(['TEST_ENV_VAR' => 'new_value']);

        $this->assertSame('original_value', getenv('TEST_ENV_VAR'));
    }

    public function testHttpVarIsPartiallyOverriden(): void
    {
        $_SERVER['HTTP_TEST_ENV_VAR'] = 'http_value';

        $dotenv = new Dotenv();
        $dotenv->populate(['HTTP_TEST_ENV_VAR' => 'env_value']);

        $this->assertSame('env_value', getenv('HTTP_TEST_ENV_VAR'));
        $this->assertSame('env_value', $_ENV['HTTP_TEST_ENV_VAR']);
        $this->assertSame('http_value', $_SERVER['HTTP_TEST_ENV_VAR']);
    }

    public function testEnvVarIsOverriden(): void
    {
        putenv('TEST_ENV_VAR_OVERRIDEN=original_value');

        $dotenv = new Dotenv();
        $dotenv->populate(['TEST_ENV_VAR_OVERRIDEN' => 'new_value'], true);

        $this->assertSame('new_value', getenv('TEST_ENV_VAR_OVERRIDEN'));
        $this->assertSame('new_value', $_ENV['TEST_ENV_VAR_OVERRIDEN']);
        $this->assertSame('new_value', $_SERVER['TEST_ENV_VAR_OVERRIDEN']);
    }

    public function testMemorizingLoadedVarsNamesInSpecialVar(): void
    {
        // Special variable not exists
        unset($_ENV['SYMFONY_DOTENV_VARS']);
        unset($_SERVER['SYMFONY_DOTENV_VARS']);
        putenv('SYMFONY_DOTENV_VARS');

        unset($_ENV['APP_DEBUG']);
        unset($_SERVER['APP_DEBUG']);
        putenv('APP_DEBUG');
        unset($_ENV['DATABASE_URL']);
        unset($_SERVER['DATABASE_URL']);
        putenv('DATABASE_URL');

        $dotenv = new Dotenv();
        $dotenv->populate(['APP_DEBUG' => '1', 'DATABASE_URL' => 'mysql://root@localhost/db']);

        $this->assertSame('APP_DEBUG,DATABASE_URL', getenv('SYMFONY_DOTENV_VARS'));

        // Special variable has a value
        $_ENV['SYMFONY_DOTENV_VARS'] = 'APP_ENV';
        $_SERVER['SYMFONY_DOTENV_VARS'] = 'APP_ENV';
        putenv('SYMFONY_DOTENV_VARS=APP_ENV');

        $_ENV['APP_DEBUG'] = '1';
        $_SERVER['APP_DEBUG'] = '1';
        putenv('APP_DEBUG=1');
        unset($_ENV['DATABASE_URL']);
        unset($_SERVER['DATABASE_URL']);
        putenv('DATABASE_URL');

        $dotenv = new Dotenv();
        $dotenv->populate(['APP_DEBUG' => '0', 'DATABASE_URL' => 'mysql://root@localhost/db']);
        $dotenv->populate(['DATABASE_URL' => 'sqlite:///somedb.sqlite']);

        $this->assertSame('APP_ENV,DATABASE_URL', getenv('SYMFONY_DOTENV_VARS'));
    }

    public function testOverridingEnvVarsWithNamesMemorizedInSpecialVar(): void
    {
        putenv('SYMFONY_DOTENV_VARS='.$_SERVER['SYMFONY_DOTENV_VARS'] = 'FOO,BAR,BAZ');

        putenv('FOO=foo');
        putenv('BAR=bar');
        putenv('BAZ=baz');
        putenv('DOCUMENT_ROOT=/var/www');

        $dotenv = new Dotenv();
        $dotenv->populate(['FOO' => 'foo1', 'BAR' => 'bar1', 'BAZ' => 'baz1', 'DOCUMENT_ROOT' => '/boot']);

        $this->assertSame('foo1', getenv('FOO'));
        $this->assertSame('bar1', getenv('BAR'));
        $this->assertSame('baz1', getenv('BAZ'));
        $this->assertSame('/var/www', getenv('DOCUMENT_ROOT'));
    }
}
