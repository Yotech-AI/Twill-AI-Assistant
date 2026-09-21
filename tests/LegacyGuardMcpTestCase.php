<?php

namespace TwillAi\Tests;

/**
 * A host on the old setup instructions: passport.guard set to twill_users so
 * Passport's own /oauth/authorize was the connector's approval screen. Such a
 * host has no customer OAuth of its own, and connectors it approved before
 * the connector had its own routes must keep working.
 */
abstract class LegacyGuardMcpTestCase extends McpTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('passport.guard', 'twill_users');
    }
}
