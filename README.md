# S3 Markdown

WordPress plugin that renders markdown files from an AWS S3 bucket via shortcode. Content is cached for 24 hours with manual flush available from the admin settings.

## Installation

Copy the `s3-markdown` directory into `wp-content/plugins/` and activate in WP Admin > Plugins.

## Configuration

Go to **Settings > S3 Markdown** and enter:

- **Bucket Name** — your S3 bucket
- **Region** — e.g. `eu-west-2` (defaults to `us-east-1`)
- **Access Key ID** — IAM user with `s3:GetObject` permission on the bucket
- **Secret Access Key**

## Usage

```
[s3md]                         <!-- renders index.md -->
[s3md file="notes/update.md"]  <!-- renders a specific file -->
```

The file attribute must end in `.md` and may contain alphanumeric characters, hyphens, underscores, slashes, and dots.

Output is wrapped in `<div class="s3md-content">` for CSS targeting.

## Caching

Rendered HTML is cached as a WordPress transient (24-hour TTL). To force a refresh, use the **Flush Markdown Cache** button on the settings page.

The cache tracking is Redis-compatible — it stores transient keys in a separate option and deletes them individually rather than relying on database LIKE queries.

## Security

- Markdown is rendered with Parsedown safe mode (all HTML in source markdown is escaped)
- File paths are validated with regex and checked for directory traversal
- Admin actions require `manage_options` capability and nonce verification
- AWS credentials are stored in `wp_options` and displayed as password fields

## Dependencies

- [Parsedown 1.7.4](https://github.com/erusev/parsedown) (MIT, bundled)
