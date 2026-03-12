# Analytics MCP Server — WordPress Plugin

A WordPress plugin that exposes a **Model Context Protocol (MCP)** endpoint, letting Claude Desktop query your Google Search Console and Google Analytics 4 data directly in natural language.

---

## Table of Contents

1. [What It Does](#what-it-does)
2. [Installation](#installation)
3. [Google Service Account Setup](#google-service-account-setup)
4. [Google Analytics 4 Setup](#google-analytics-4-setup)
5. [Google Search Console Setup](#google-search-console-setup)
6. [Plugin Configuration](#plugin-configuration)
7. [Team Setup — Claude Desktop](#team-setup--claude-desktop)
8. [Available Tools](#available-tools)
9. [Example Prompts](#example-prompts)
10. [Troubleshooting](#troubleshooting)

---

## What It Does

- Adds a REST endpoint at `https://yoursite.com/wp-json/mcp/v1/mcp`
- Authenticates Claude Desktop via a shared API key
- Connects to Google APIs server-side using a service account — credentials never leave your server
- Provides 7 tools across GSC and GA4, all with **read-only** access

---

## Installation

1. Upload the `wp-analytics-mcp` folder to `/wp-content/plugins/`
2. Activate the plugin via **WP Admin → Plugins**
3. Go to **Settings → Analytics MCP** to configure

---

## Google Service Account Setup

> **You do this once.** Your whole team connects through one shared service account.

A Google Cloud service account is like a bot user — it has its own email address and credentials, and you grant it read-only access to your analytics properties.

### Step 1 — Create or Select a Google Cloud Project

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and sign in
2. Click the **project dropdown** at the top of the page
3. Either select an existing project, or click **New Project**, name it (e.g. `FooPlugins Analytics`), and click **Create**

### Step 2 — Enable the Required APIs

1. In the left sidebar go to **APIs & Services → Library**
2. Search for **Google Analytics Data API** → click it → click **Enable**
3. Search for **Google Search Console API** → click it → click **Enable**

> If you see "API already enabled" — nothing to do, move on.

### Step 3 — Create the Service Account

1. In the left sidebar go to **IAM & Admin → Service Accounts**
2. Click **+ Create Service Account**
3. Fill in a **Service account name** — use something like `fooplugins-mcp`
4. The **Service account ID** (email) will auto-fill — **copy this email**, you'll need it in the next two sections
5. Click **Create and Continue**
6. Skip the optional role/permissions step — just click **Continue**, then **Done**

### Step 4 — Download the JSON Key File

1. In the service accounts list, click on your new service account's **name**
2. Go to the **Keys** tab
3. Click **Add Key → Create new key**
4. Select **JSON** and click **Create**
5. A `.json` file downloads automatically — **this is the file you paste into the plugin**

> ⚠️ **Keep this file safe.** Never commit it to a public Git repo or share it over email/Slack. Anyone with it can read your analytics data. Treat it like a password.

---

## Google Analytics 4 Setup

You need the service account email from Step 3 (looks like `fooplugins-mcp@your-project.iam.gserviceaccount.com`).

### Step 5 — Add the Service Account to GA4

1. Go to [analytics.google.com](https://analytics.google.com) and sign in
2. Click the **Admin** cog icon in the bottom-left
3. In the **Account** column, click **Account Access Management** (grants access to all properties), or **Property Access Management** to restrict to one property
4. Click the blue **+** button → **Add users**
5. Paste the service account email into the **Email addresses** field
6. Set the role to **Viewer** — read-only, cannot modify anything
7. Click **Add**

> 💡 **Find your GA4 Property ID:** Go to Admin → Property → Property Settings. It's a number like `123456789`. You'll need this when prompting Claude to run GA4 reports.

---

## Google Search Console Setup

Same service account email as above.

### Step 6 — Add the Service Account to Search Console

1. Go to [search.google.com/search-console](https://search.google.com/search-console) and sign in
2. Select the property you want Claude to access from the left sidebar
3. Go to **Settings → Users and permissions**
4. Click **Add user**
5. Paste the service account email into the **Email address** field
6. Set permission to **Full** — _not_ Restricted (Restricted blocks URL inspection)
7. Click **Add**

> 💡 Repeat Step 6 for each GSC property you want Claude to access. Each property needs to be added separately.

---

## Plugin Configuration

### Step 7 — Add Your Credentials

1. Go to **WP Admin → Settings → Analytics MCP**
2. Open your downloaded `.json` file in any text editor, select all, and copy
3. Paste the full JSON into the **Service Account JSON** field
4. Click **Save Settings** — the page will confirm the service account email it loaded

### Step 8 — Generate an API Key

1. On the same settings page, click **Generate New API Key**
2. The config block at the top of the page will update with your key
3. Copy the full config block — this is what each teammate pastes into Claude Desktop

---

## Team Setup — Claude Desktop

Each teammate does this once. Takes ~5 minutes. No Google account setup required on their end.

### Step 9 — Configure Claude Desktop

1. Open Claude Desktop → **Settings → Developer → Edit Config**
2. Paste the config block from the plugin settings page into the JSON file:

```json
{
  "mcpServers": {
    "analytics-mcp": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote@0.1.30",
        "https://yoursite.com/wp-json/mcp/v1/mcp",
        "--header",
        "Authorization: Bearer YOUR_API_KEY"
      ]
    }
  }
}
```

3. Save the file and **fully quit and restart** Claude Desktop
4. Go to Settings → Developer — you should see `analytics-mcp` with status **Running**

---

## Available Tools

| Tool | Source | Description |
|---|---|---|
| `gsc_list_sites` | GSC | List all Search Console properties the service account can access |
| `gsc_query_search_analytics` | GSC | Query clicks, impressions, CTR, and average position |
| `gsc_inspect_url` | GSC | Check the indexing status of a specific URL |
| `gsc_list_sitemaps` | GSC | List submitted sitemaps for a property |
| `ga4_run_report` | GA4 | Run a full analytics report — sessions, users, conversions, etc. |
| `ga4_run_realtime_report` | GA4 | Real-time active users in the last 30 minutes |
| `ga4_list_metadata` | GA4 | List all available GA4 dimensions and metrics |

---

## Example Prompts

**Google Search Console**
- "List all my Search Console properties"
- "Show me the top 20 queries for fooplugins.com with more than 50 impressions last month"
- "Which pages have high impressions but low click-through rate?"
- "Is https://fooplugins.com/foogallery/ indexed in Google?"

**Google Analytics 4**
- "Give me a traffic summary for GA4 property 123456789 for the last 30 days"
- "What are my top 10 pages by sessions this month?"
- "Compare sessions by device category over the last 90 days"
- "How many active users do I have right now, broken down by country?"

---

## Troubleshooting

| Error | Fix |
|---|---|
| `"No service account JSON configured"` | Paste the full `.json` file contents into the plugin settings and save |
| `"Could not load private key"` | Make sure you copied the entire JSON — don't truncate the `private_key` field |
| HTTP 403 from Google | The service account hasn't been added to the GA4 or GSC property yet — check the setup sections above |
| `"Invalid or missing API key"` | The key in Claude Desktop's config doesn't match the one on the settings page — regenerate and update |
| Status shows "Failed" in Claude Desktop | Go to WP Admin → Settings → Permalinks and click **Save Changes** to flush routes |
| Tools appear but return no data | Verify the service account email is added as Viewer in GA4 and Full User in GSC |
| `rest_no_route` on the `/mcp` endpoint | Plugin isn't active, or permalinks need flushing — see above |

**Health check URL:** `https://yoursite.com/wp-json/mcp/v1/health` — should return `{"status":"ok"}` if the plugin is running correctly.
