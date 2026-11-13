# Changelog

Notable changes to `yotech-ai/twill-cms-ai-assistant`. Releases before 1.3.0 are described on the [GitHub releases page](https://github.com/Yotech-AI/Twill-AI-Assistant/releases).

## 1.4.0 (2026-11-13)

### Added

- **Self-service MCP connectors.** A Twill user connects any AI app (Claude, ChatGPT, Mistral Le Chat or another MCP client) with only `https://<site>/mcp/twill`. The app registers itself, the user logs in to Twill and approves, and that approval registers the connector, with a Twill user of its own for attribution. Config: `twill-ai.mcp.self_service` (`TWILL_AI_MCP_SELF_SERVICE`, default `true`) and `twill-ai.mcp.allowed_redirect_hosts` (default `null`, any app).
- **A Connectors page** under Twill AI, for users who may approve. It lists every connector with the host its access goes to, who approved it and when (or "artisan"), its last use and live tokens. It has Revoke, and "Add an app by hand" for an app that can't register itself.
- `mcp:doctor` shows self-service, the allowed apps and the apps waiting for approval.

### Changed

- With self-service on, only users who pass Twill's edit gate may approve a connector. A view-only user is asked to get an editor or admin.
- The approval screen shows the name the app gave itself, where its access goes and what it may do.
- With self-service on, `/oauth/register` accepts only the redirect URIs OAuth 2.1 allows: https, http on a loopback address, or a private-use scheme. Anything else gets `invalid_redirect_uri`. The endpoint is shared with the host application, so this applies to its own MCP clients too.
- `update_content` never changes which languages are active; `locales` in an update payload is ignored.

### Fixed

- `update_content` replaced a translated field as a whole, so a payload with only `en` cleared `nl` and `de`. Every locale the payload leaves out now keeps its text, also in blocks sent with their `id`, and a locale sent as `""` or `null` is cleared.

### Upgrading from 1.3

```bash
composer update yotech-ai/twill-cms-ai-assistant
php artisan migrate
php artisan route:cache   # only if you cache routes
```

- The migration adds two nullable columns to `mcp_clients`: who approved a connector, and when.
- Self-service is on by default, so Twill editors can connect apps right after the update. If you published `config/twill-ai.php`, add `'self_service' => env('TWILL_AI_MCP_SELF_SERVICE', true)` and `'allowed_redirect_hosts' => null` to its `mcp` section, or the env variable is never read.
- Existing connectors and their tokens keep working; the Connectors page lists them as approved by "artisan".
- If you published the package's views, compare your `mcp/authorize.blade.php` with the package's: the new approval details are only in the package's copy. Its forms are unchanged.

## 1.3.0 (2026-11-12)

### Added

- laravel/ai 0.11 support, alongside 0.3 and 0.10.
- The MCP connector runs its own OAuth authorization server: its own approval screen at `/twill-ai/oauth/authorize` (`twill-ai.mcp.oauth_prefix`), behind the CMS login, and its own discovery documents. A host's own OAuth setup is left alone.
- `mcp:doctor` follows the sign-in chain the way Claude does.

### Changed

- `passport.guard` no longer has to be `twill_users`.
- `mcp:client-create` binds clients to the CMS user provider, so other Passport guards refuse their tokens.
- Composer refuses untested laravel/mcp and Passport versions.

### Fixed

- A host that calls `Mcp::oauthRoutes()` itself keeps its `POST /oauth/register` and the rate limit on it.
