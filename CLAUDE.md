# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin called "Nevma Inventory Webhook Liberta" that receives inventory updates from an external partner (Liberta) and synchronizes stock levels with WooCommerce products.

**Site**: This plugin is specifically built for entos.gr

## Architecture

### REST API Endpoint
- **Endpoint**: `/wp-json/nvm/v1/inventory-liberta`
- **Method**: POST
- **Authentication**: Currently bypassed (returns true at liberta.php:72), but has IP whitelist logic (185.186.84.147, 185.186.84.30) and API key validation ready for activation

### Core Components

1. **Webhook Handler** (`nvm_handle_inventory_webhook`): Processes single or batch inventory updates from the "rows" array or legacy single object format

2. **Product Matching** (`nvm_find_product_by_code`):
   - First attempts to match by SKU using `codeentos` field
   - Fallback to custom meta field `liberta_code` using `code` field

3. **Asynchronous Processing**: Uses Action Scheduler to queue inventory updates via `hook_nvm_update_product_inventory` action

4. **Inventory Update** (`nvm_update_product_inventory`):
   - Only saves product if changes detected (stock quantity, manage stock flag, or stock status)
   - Updates parent variable product stock status when variation is modified
   - Stores last update timestamp in `_nvm_last_webhook_liberta_update` meta field
   - Fires `nvm_inventory_updated` action hook for extensibility

5. **Logging System**:
   - Database table: `wp_nvm_webhook_logs`
   - Debug logging controlled by `NVM_WEBHOOK_LOGS` constant (currently false at liberta.php:27)
   - Admin interface at Tools > Webhook Logs showing recent activity

### Payload Structure

Accepts two formats:

**Batch format**:
```json
{
  "rows": [
    {
      "code": "LIBERTA123",
      "codeentos": "SKU123",
      "diathesima": 42
    }
  ]
}
```

**Legacy single object**:
```json
{
  "code": "LIBERTA123",
  "codeentos": "SKU123",
  "diathesima": 42
}
```

### Configuration

- **API Key**: Defined at liberta.php:26 as `NVM_WEBHOOK_API_KEY` (hardcoded, should be moved to wp-config.php for production)
- **Debug Logs**: Controlled by `NVM_WEBHOOK_LOGS` constant (line 27)

## Development Workflow

This is a single-file WordPress plugin with no build process. Changes to `liberta.php` are immediately active when the plugin is enabled in WordPress.

### Setup

Install development dependencies:
```bash
composer install
```

### Code Quality Tools

Run all quality checks:
```bash
composer test
```

**PHP CodeSniffer** - Check coding standards:
```bash
composer phpcs
```

**PHP Code Beautifier and Fixer** - Auto-fix coding standards issues:
```bash
composer phpcbf
```

**PHPStan** - Static analysis (Level 6):
```bash
composer phpstan
```

The project follows WordPress and WooCommerce coding standards and includes:
- WordPress Core standards
- WordPress Extra standards
- WordPress Documentation standards
- WooCommerce Core standards
- PHP 7.4+ compatibility checks (WordPress-aware)
- Security checks (escaping, nonce verification, sanitization)
- Database query validation
- Minimum WordPress version: 5.9
- Minimum WooCommerce version: 6.0

### Testing the Webhook

Test the endpoint using curl:
```bash
curl -X POST https://your-site.com/wp-json/nvm/v1/inventory-liberta \
  -H "Content-Type: application/json" \
  -d '{"rows":[{"code":"TEST123","codeentos":"SKU123","diathesima":10}]}'
```

### Enabling Authentication

To enable IP and API key validation:
1. Remove or comment out line 72: `return true;`
2. Ensure IP whitelist is configured (lines 81-84)
3. Move API key to wp-config.php and update reference at line 104

### Viewing Logs

- Navigate to WordPress Admin > Tools > Webhook Logs
- Or query database: `SELECT * FROM wp_nvm_webhook_logs ORDER BY created_at DESC`

## Key Behavior Notes

- Stock updates are queued asynchronously via Action Scheduler (not processed immediately in webhook response)
- Returns HTTP 200 on success, 207 Multi-Status on partial failures
- Variation stock updates trigger parent variable product recalculation
- Product saves are optimized to only occur when actual changes are detected
