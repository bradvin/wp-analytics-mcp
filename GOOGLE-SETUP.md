# Google Service Account Setup Guide

For the **Analytics MCP Server** WordPress plugin — connects Claude Desktop to your Google Search Console and Google Analytics 4 data.

> **Read-only access only.** The service account cannot modify your analytics configuration, delete data, or change any settings.

---

## What You Need Before Starting

- A Google account with admin access to your GA4 property
- A Google account with Full User access to your GSC property
- A Google Cloud project (free — you may already have one)
- About 10 minutes

---

## Part 1 — Google Cloud Console

### Step 1: Open Google Cloud Console

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and sign in
2. At the top, click the project dropdown and either select an existing project or click **New Project**
3. Name it something like `FooPlugins Analytics` and click **Create**

---

### Step 2: Enable the Required APIs

1. In the left sidebar, go to **APIs & Services → Library**
2. Search for **Google Analytics Data API** → click it → click **Enable**
3. Search for **Google Search Console API** → click it → click **Enable**

> Already enabled? Great — move on.

---

### Step 3: Create the Service Account

1. In the left sidebar, go to **IAM & Admin → Service Accounts**
2. Click **+ Create Service Account**
3. Set the name to something like `fooplugins-mcp` — the email will auto-fill (e.g. `fooplugins-mcp@your-project.iam.gserviceaccount.com`). **Copy this email — you'll need it in Parts 2 and 3.**
4. Click **Create and Continue**
5. Skip the optional role/permissions screen — just click **Continue**
6. Click **Done**

---

### Step 4: Download the JSON Key File

This is the credentials file you paste into the plugin settings.

1. Click on your service account name in the list
2. Go to the **Keys** tab
3. Click **Add Key → Create new key**
4. Select **JSON** and click **Create**
5. A `.json` file downloads automatically

> ⚠️ **Keep this file safe.** Never commit it to a public Git repo or share it in Slack or email. Anyone with it can read your analytics data.

---

## Part 2 — Google Analytics 4

Grant the service account read-only access to your GA4 property. You'll need the service account email from Step 3.

### Step 5: Add the Service Account to GA4

1. Go to [analytics.google.com](https://analytics.google.com) and sign in
2. Click the **Admin** cog in the bottom-left corner
3. In the **Account** column, click **Account Access Management** (gives access to all properties) — or click **Property Access Management** in the Property column to restrict to one property
4. Click the blue **+** button → **Add users**
5. Paste the service account email into the **Email addresses** field
6. Set the role to **Viewer** (read-only)
7. Click **Add**

> **Find your GA4 Property ID:** Admin → Property → Property Settings. It's a number like `123456789`. You'll need this when running reports in Claude.

---

## Part 3 — Google Search Console

### Step 6: Add the Service Account to Search Console

1. Go to [search.google.com/search-console](https://search.google.com/search-console) and sign in
2. Select the property you want Claude to access
3. In the left sidebar, scroll down and click **Settings**
4. Click **Users and permissions**
5. Click **Add user** (top right)
6. Paste the service account email into the **Email address** field
7. Set permission to **Full** (Restricted won't work for URL inspection)
8. Click **Add**

> Repeat Step 6 for each additional GSC property you want Claude to access. Each property is added separately.

---

## Part 4 — Add Credentials to the Plugin

### Step 7: Paste the JSON Key into WordPress

1. In WordPress, go to **Settings → Analytics MCP**
2. Open your downloaded `.json` file in any text editor
3. Select all the text (`Cmd+A` / `Ctrl+A`) and copy it
4. Paste it into the **Service Account JSON** text area on the settings page
5. Click **Save Settings** — the page confirms the service account email it loaded

---

### Step 8: Generate Your API Key

1. On the settings page, scroll to **Generate a New API Key** and click the button
2. Copy the config block shown at the top of the page
3. This is what your teammates paste into Claude Desktop

---

## Part 5 — Connect Claude Desktop

Each team member does this once. No Google account setup required on their end.

### Step 9: Configure Claude Desktop

1. Open Claude Desktop → **Settings → Developer → Edit Config**
2. Paste the config block from the plugin settings page into the JSON file
3. Save (`Cmd+S` / `Ctrl+S`)
4. Fully quit and restart Claude Desktop
5. In Settings → Developer you should see **analytics-mcp** with a **Running** status

---

## Example Prompts

Once connected, try these to verify everything works:

**Google Search Console**
- `"List all my Search Console properties"`
- `"Show me the top 20 queries for fooplugins.com with more than 50 impressions last month"`
- `"Which pages have high impressions but low click-through rate?"`
- `"Is https://fooplugins.com/foogallery/ indexed in Google?"`

**Google Analytics 4**
- `"Give me a traffic summary for GA4 property 123456789 for the last 30 days"`
- `"What are my top 10 pages by sessions this month?"`
- `"Compare sessions by device category over the last 90 days"`
- `"How many active users do I have right now, by country?"`

---

## Troubleshooting

| Error | Fix |
|---|---|
| `"No service account JSON configured"` | Paste the full `.json` file contents into the plugin settings and save |
| `"Could not load private key"` | Make sure you copied the entire JSON including the `private_key` field — don't truncate it |
| HTTP 403 from Google | The service account hasn't been added to the GA4 or GSC property yet — check Parts 2 and 3 |
| `"Invalid or missing API key"` | The key in Claude Desktop's config doesn't match the plugin — regenerate and update the config |
| Status shows `Failed` in Claude Desktop | Go to WP Admin → **Settings → Permalinks** and click **Save Changes** to flush routes |
| Tools appear but return no data | Double-check the service account email is added as Viewer in GA4 and Full User in GSC |
| Health check returns 404 | Plugin may not be activated — check WP Admin → Plugins |
