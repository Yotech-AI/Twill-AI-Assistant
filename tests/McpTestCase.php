<?php

namespace TwillAi\Tests;

use Laravel\Passport\Passport;
use RuntimeException;
use TwillAi\Models\TwillUser;

/**
 * Base case for the MCP connector suite.
 *
 * `twill-ai.mcp.enabled` has to be true before the package boots — the connector
 * is gated in TwillAiServiceProvider — so this is a separate TestCase rather
 * than a beforeEach.
 */
abstract class McpTestCase extends TestCase
{
    /**
     * Generated once per process, not per test: RSA keygen is slow enough to
     * dominate the suite otherwise.
     */
    protected static ?string $passportKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Passport signs and verifies access tokens with an RSA keypair that
        // `php artisan passport:keys` normally writes per environment. Testbench
        // has none, so the suite makes its own throwaway pair.
        Passport::loadKeysFrom($this->passportKeyPath());
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];

        $config->set('twill-ai.mcp.enabled', true);

        // Passport requires the authenticatable behind the `twill-mcp` guard to
        // implement OAuthenticatable, which Twill's own user model does not.
        // Pointing twill.models.user at the package's subclass is exactly the
        // step a host performs, and Twill reads this when registering the
        // twill_users provider the guard resolves through.
        $config->set('twill.models.user', TwillUser::class);

        // Twill copies twill.models.user into the twill_users auth provider
        // while it registers, which in Testbench is before this runs. A host
        // sets twill.models.user in config/twill.php, present from the start,
        // so the provider sees it; here it is set directly to match. Only the
        // full sign-in test resolves a user through this provider, since the
        // others use Passport::actingAs.
        $config->set('auth.providers.twill_users.model', TwillUser::class);

        // passport.guard is deliberately left at Passport's own default, `web`.
        // The connector runs its own authorization server behind the CMS login,
        // so a host no longer changes this global value for it; the suite runs
        // the way such a host does. LegacyGuardMcpTestCase covers a host that
        // still sets it to twill_users from the old setup instructions.
    }

    protected function passportKeyPath(): string
    {
        if (static::$passportKeyPath !== null) {
            return static::$passportKeyPath;
        }

        $directory = __DIR__.'/Fixtures/passport-keys';

        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $private = $directory.'/oauth-private.key';
        $public = $directory.'/oauth-public.key';

        if (! file_exists($private) || ! file_exists($public)) {
            // ext-openssl needs an openssl.cnf to generate a key, and Windows
            // PHP builds routinely ship without one — openssl_pkey_new then
            // fails with "configuration file routines::no such file". Writing a
            // minimal config keeps the suite self-contained on every platform
            // rather than depending on a system-wide OpenSSL install.
            $config = $directory.'/openssl.cnf';

            if (! file_exists($config)) {
                file_put_contents($config, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
            }

            $options = [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $config,
            ];

            $key = openssl_pkey_new($options);

            if ($key === false || ! openssl_pkey_export($key, $privatePem, null, $options)) {
                $errors = [];

                while ($error = openssl_error_string()) {
                    $errors[] = $error;
                }

                throw new RuntimeException(
                    'Could not generate a Passport test keypair: '.(implode('; ', $errors) ?: 'unknown OpenSSL error')
                );
            }

            file_put_contents($private, $privatePem);
            file_put_contents($public, openssl_pkey_get_details($key)['key']);
        }

        // league/oauth2-server refuses a key file that is group- or
        // world-readable, and skips that check entirely on Windows — so a suite
        // developed here passes locally and fails on a Linux CI runner with
        // "Key file permissions are not correct". Applied whenever the path is
        // first resolved rather than only at generation, so a pair left behind
        // by an earlier run with wrong permissions is repaired instead of
        // failing forever.
        foreach ([$private, $public] as $file) {
            @chmod($file, 0o600);
        }

        return static::$passportKeyPath = $directory;
    }
}
