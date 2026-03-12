<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Admin {

    public static function register_menu(): void {
        add_options_page(
            'Analytics MCP Server',
            'Analytics MCP',
            'manage_options',
            'wp-analytics-mcp',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'wp_mcp_settings', 'wp_mcp_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'wp_mcp_settings', 'wp_mcp_service_account_json', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_json' ],
        ] );
    }

    public static function sanitize_json( string $input ): string {
        $input = trim( $input );

        // Allow clearing the field.
        if ( $input === '' ) {
            return '';
        }

        // Warn on invalid JSON but still save so the user can fix iteratively.
        $decoded = json_decode( $input, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            add_settings_error( 'wp_mcp_service_account_json', 'invalid_json', 'Service account JSON is not valid JSON. Please check and try again.', 'error' );
            return $input;
        }

        // Warn on missing required fields.
        $missing = [];
        foreach ( [ 'type', 'private_key', 'client_email' ] as $key ) {
            if ( empty( $decoded[ $key ] ) ) {
                $missing[] = $key;
            }
        }
        if ( $missing ) {
            add_settings_error( 'wp_mcp_service_account_json', 'missing_key', 'Service account JSON is missing required field(s): ' . implode( ', ', $missing ), 'error' );
        }

        return $input;
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_POST['generate_api_key'] ) && check_admin_referer( 'wp_mcp_generate_key' ) ) {
            $new_key = WP_MCP_Auth::generate_key();
            update_option( 'wp_mcp_api_key', $new_key );
            echo '<div class="notice notice-success"><p>New API key generated and saved.</p></div>';
        }

        $api_key    = get_option( 'wp_mcp_api_key', '' );
        $sa_json    = get_option( 'wp_mcp_service_account_json', '' );
        $mcp_url    = rest_url( 'mcp/v1/mcp' );
        $health_url = rest_url( 'mcp/v1/health' );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';

        ?>
        <div class="wrap">
            <h1>Analytics MCP Server</h1>
            <p style="color:#50575e;">Connects Claude Desktop to your Google Search Console and Google Analytics 4 data via MCP.</p>

            <?php if ( $api_key && $active_tab !== 'setup' ) : ?>
            <div class="notice notice-info" style="padding:12px 16px; background:#f0f7ff; border-left-color:#0073aa; margin-bottom:0;">
                <h2 style="margin-top:4px; font-size:14px;">📋 Claude Desktop Config</h2>
                <p style="margin-bottom:6px;">Each teammate pastes this into <strong>Claude Desktop → Settings → Developer → Edit Config</strong>:</p>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:14px 16px; border-radius:4px; overflow-x:auto; font-size:12px; margin:0 0 8px;"><?php echo esc_html( json_encode( [
                    'mcpServers' => [
                        'analytics-mcp' => [
                            'command' => 'npx',
                            'args'    => [
                                '-y',
                                'mcp-remote@latest',
                                $mcp_url,
                                '--header',
                                'Authorization: Bearer ' . $api_key,
                            ],
                        ],
                    ],
                ], JSON_PRETTY_PRINT ) ); ?></pre>
                <p style="margin:0; font-size:12px;">
                    <strong>Health check:</strong>
                    <a href="<?php echo esc_url( $health_url ); ?>" target="_blank" style="color:#2271b1;"><?php echo esc_html( $health_url ); ?></a>
                </p>
            </div>
            <?php endif; ?>

            <!-- Nav tabs -->
            <nav class="nav-tab-wrapper wp-clearfix" style="margin-top:20px;">
                <a href="?page=wp-analytics-mcp&tab=settings"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    ⚙️ Settings
                </a>
                <a href="?page=wp-analytics-mcp&tab=tools"
                   class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
                    🛠️ Available Tools
                </a>
                <a href="?page=wp-analytics-mcp&tab=setup"
                   class="nav-tab <?php echo $active_tab === 'setup' ? 'nav-tab-active' : ''; ?>">
                    🔑 Google Setup Help
                </a>
            </nav>

            <div style="background:#fff; border:1px solid #c3c4c7; border-top:none; padding:24px; max-width:900px;">

            <?php if ( $active_tab === 'settings' ) : ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'wp_mcp_settings' ); ?>

                    <h2 style="margin-top:0;">1. API Key</h2>
                    <p>This key authenticates your team's Claude Desktop instances. Keep it secret.</p>
                    <table class="form-table">
                        <tr>
                            <th>Current API Key</th>
                            <td>
                                <?php if ( $api_key ) : ?>
                                    <code style="font-size:12px; background:#f6f7f7; padding:6px 10px; border-radius:4px; word-break:break-all;"><?php echo esc_html( $api_key ); ?></code>
                                <?php else : ?>
                                    <em style="color:#d63638;">No key set — generate one below.</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Set API Key Manually</th>
                            <td>
                                <input type="text" name="wp_mcp_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                                <p class="description">Or leave blank and use the generate button below.</p>
                            </td>
                        </tr>
                    </table>

                    <h2>2. Google Service Account</h2>
                    <p>Paste the full contents of your Google Cloud service account <code>.json</code> key file.</p>
                    <p>Need help getting this? See the <a href="?page=wp-analytics-mcp&tab=setup"><strong>🔑 Google Setup Help</strong></a> tab.</p>
                    <table class="form-table">
                        <tr>
                            <th>Service Account JSON</th>
                            <td>
                                <textarea
                                    name="wp_mcp_service_account_json"
                                    rows="12"
                                    style="width:100%; font-family:monospace; font-size:12px;"
                                    placeholder='{"type": "service_account", "project_id": "...", "private_key": "...", ...}'
                                ><?php echo esc_textarea( $sa_json ); ?></textarea>
                                <?php if ( $sa_json ) :
                                    $decoded = json_decode( $sa_json, true ); ?>
                                    <p style="color:#00a32a; margin-top:6px;">✅ Loaded — service account: <strong><?php echo esc_html( $decoded['client_email'] ?? 'unknown' ); ?></strong></p>
                                <?php else : ?>
                                    <p class="description">The service account needs <strong>Viewer</strong> access in GA4 and <strong>Full User</strong> access in Search Console.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( 'Save Settings' ); ?>
                </form>

                <hr style="margin:24px 0;">

                <h2>Generate a New API Key</h2>
                <p>This replaces the existing key. You'll need to update the config on each team member's Claude Desktop.</p>
                <form method="post">
                    <?php wp_nonce_field( 'wp_mcp_generate_key' ); ?>
                    <input type="hidden" name="generate_api_key" value="1" />
                    <?php submit_button( 'Generate New API Key', 'secondary', 'submit', false ); ?>
                </form>

            <?php elseif ( $active_tab === 'tools' ) : ?>

                <h2 style="margin-top:0;">Available Tools</h2>
                <p>Once connected, Claude Desktop can use these tools:</p>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th style="width:220px;">Tool</th>
                            <th style="width:60px;">Source</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ( WP_MCP_GSC::get_tools() as $tool ) {
                            echo '<tr><td><code>' . esc_html( $tool['name'] ) . '</code></td>'
                               . '<td><span style="color:#2271b1;font-weight:600;">GSC</span></td>'
                               . '<td>' . esc_html( $tool['description'] ) . '</td></tr>';
                        }
                        foreach ( WP_MCP_GA4::get_tools() as $tool ) {
                            echo '<tr><td><code>' . esc_html( $tool['name'] ) . '</code></td>'
                               . '<td><span style="color:#1a8a00;font-weight:600;">GA4</span></td>'
                               . '<td>' . esc_html( $tool['description'] ) . '</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <h2 style="margin-top:32px;">Example Prompts</h2>
                <table class="widefat" style="margin-top:12px;">
                    <thead><tr><th>Ask Claude...</th><th>What it does</th></tr></thead>
                    <tbody>
                        <tr><td><em>"List all my Search Console properties"</em></td><td>Shows all GSC sites the service account can access</td></tr>
                        <tr><td><em>"Top 20 queries for fooplugins.com last month"</em></td><td>Clicks, impressions, CTR and position by search query</td></tr>
                        <tr><td><em>"Is this URL indexed in Google?"</em></td><td>Checks indexing status via GSC URL inspection</td></tr>
                        <tr><td><em>"Traffic summary for GA4 property 123456789"</em></td><td>Sessions, users, pageviews for the date range</td></tr>
                        <tr><td><em>"Active users right now by country"</em></td><td>Real-time GA4 report</td></tr>
                        <tr><td><em>"Compare mobile vs desktop sessions last 90 days"</em></td><td>GA4 report by deviceCategory dimension</td></tr>
                    </tbody>
                </table>

            <?php elseif ( $active_tab === 'setup' ) : ?>

                <h2 style="margin-top:0;">🔑 Google Service Account Setup</h2>
                <p style="color:#50575e;">Follow these steps to create a service account and grant it read-only access to your GA4 and GSC properties. Takes about 10 minutes.</p>

                <div style="background:#e6f4ea; border-left:4px solid #137333; padding:10px 16px; border-radius:0 4px 4px 0; margin-bottom:24px;">
                    <strong style="color:#137333;">Read-only access only.</strong>
                    <span style="color:#1d2327;"> The service account cannot modify your analytics configuration, delete data, or change any settings.</span>
                </div>

                <h3 style="background:#f0f7ff; border-left:4px solid #2271b1; padding:8px 14px; margin:24px 0 16px;">Part 1 — Google Cloud Console</h3>

                <h4>Step 1: Open Google Cloud Console</h4>
                <ol>
                    <li>Go to <a href="https://console.cloud.google.com" target="_blank"><strong>console.cloud.google.com</strong></a> and sign in</li>
                    <li>Click the project dropdown and select an existing project — or click <strong>New Project</strong>, name it (e.g. <code>FooPlugins Analytics</code>), and click <strong>Create</strong></li>
                </ol>

                <h4 style="margin-top:20px;">Step 2: Enable the Required APIs</h4>
                <ol>
                    <li>Go to <strong>APIs &amp; Services → Library</strong></li>
                    <li>Search for <strong>Google Analytics Data API</strong> → click it → <strong>Enable</strong></li>
                    <li>Search for <strong>Google Search Console API</strong> → click it → <strong>Enable</strong></li>
                </ol>

                <h4 style="margin-top:20px;">Step 3: Create the Service Account</h4>
                <ol>
                    <li>Go to <strong>IAM &amp; Admin → Service Accounts</strong></li>
                    <li>Click <strong>+ Create Service Account</strong></li>
                    <li>Name it <code>fooplugins-mcp</code> — the email auto-fills. <strong>Copy this email — you need it in Parts 2 and 3.</strong></li>
                    <li>Click <strong>Create and Continue</strong>, skip the role screen, click <strong>Done</strong></li>
                </ol>

                <h4 style="margin-top:20px;">Step 4: Download the JSON Key File</h4>
                <ol>
                    <li>Click on your service account name in the list</li>
                    <li>Go to the <strong>Keys</strong> tab → <strong>Add Key → Create new key</strong></li>
                    <li>Select <strong>JSON</strong> → <strong>Create</strong> — the file downloads automatically</li>
                    <li>Open it in a text editor, select all, copy, and paste it into the <a href="?page=wp-analytics-mcp&tab=settings"><strong>Settings tab</strong></a></li>
                </ol>

                <div style="background:#fef9e7; border-left:4px solid #b45309; padding:10px 16px; border-radius:0 4px 4px 0; margin:16px 0 24px;">
                    <strong style="color:#b45309;">⚠️ Keep this file safe.</strong>
                    <span style="color:#1d2327;"> Never commit it to a public Git repo or share it in Slack or email.</span>
                </div>

                <h3 style="background:#f0f7ff; border-left:4px solid #2271b1; padding:8px 14px; margin:24px 0 16px;">Part 2 — Google Analytics 4</h3>

                <h4>Step 5: Add the Service Account to GA4</h4>
                <ol>
                    <li>Go to <a href="https://analytics.google.com" target="_blank"><strong>analytics.google.com</strong></a> → <strong>Admin</strong> (cog, bottom-left)</li>
                    <li>In the Account column → <strong>Account Access Management</strong> (or Property Access Management for a single property)</li>
                    <li>Click <strong>+</strong> → <strong>Add users</strong></li>
                    <li>Paste the service account email, set role to <strong>Viewer</strong>, click <strong>Add</strong></li>
                </ol>

                <div style="background:#e6f4ea; border-left:4px solid #137333; padding:10px 16px; border-radius:0 4px 4px 0; margin:16px 0 24px;">
                    <strong style="color:#137333;">Find your GA4 Property ID:</strong>
                    <span style="color:#1d2327;"> Admin → Property → Property Settings. It's a number like <code>123456789</code> — you'll need it when running reports in Claude.</span>
                </div>

                <h3 style="background:#f0f7ff; border-left:4px solid #2271b1; padding:8px 14px; margin:24px 0 16px;">Part 3 — Google Search Console</h3>

                <h4>Step 6: Add the Service Account to Search Console</h4>
                <ol>
                    <li>Go to <a href="https://search.google.com/search-console" target="_blank"><strong>search.google.com/search-console</strong></a></li>
                    <li>Select the property you want Claude to access</li>
                    <li><strong>Settings → Users and permissions → Add user</strong></li>
                    <li>Paste the service account email, set to <strong>Full</strong> (not Restricted — Restricted blocks URL inspection), click <strong>Add</strong></li>
                </ol>

                <div style="background:#e6f4ea; border-left:4px solid #137333; padding:10px 16px; border-radius:0 4px 4px 0; margin:16px 0 24px;">
                    <strong style="color:#137333;">Multiple properties?</strong>
                    <span style="color:#1d2327;"> Repeat Step 6 for each GSC property. Each one is added separately.</span>
                </div>

                <h3 style="background:#f0f7ff; border-left:4px solid #2271b1; padding:8px 14px; margin:24px 0 16px;">Troubleshooting</h3>

                <table class="widefat striped">
                    <thead><tr><th style="width:280px;">Error</th><th>Fix</th></tr></thead>
                    <tbody>
                        <tr><td><code>"No service account JSON configured"</code></td><td>Paste the full <code>.json</code> contents into the <a href="?page=wp-analytics-mcp&tab=settings">Settings tab</a> and save</td></tr>
                        <tr><td><code>"Could not load private key"</code></td><td>Make sure you copied the entire JSON including the <code>private_key</code> field</td></tr>
                        <tr><td>HTTP 403 from Google</td><td>The service account hasn't been added to the GA4 or GSC property yet — check Parts 2 and 3</td></tr>
                        <tr><td><code>"Invalid or missing API key"</code></td><td>The key in Claude Desktop's config doesn't match the plugin — regenerate and update the config</td></tr>
                        <tr><td>Status shows <code>Failed</code> in Claude Desktop</td><td>Go to <strong>Settings → Permalinks</strong> and click <strong>Save Changes</strong> to flush routes</td></tr>
                        <tr><td>Tools appear but return no data</td><td>Double-check the service account is added as Viewer in GA4 and Full User in GSC</td></tr>
                    </tbody>
                </table>

            <?php endif; ?>

            </div>
        </div>
        <?php
    }
}
