# DSN Carfac WooCommerce Connector

A WordPress plugin that seamlessly integrates WooCommerce with Carfac ERP, enabling automatic synchronization of products, inventory, and orders between your WooCommerce store and Carfac ERP system.

## Features

- **Product Synchronization**
  - Automatic daily sync of products from Carfac ERP to WooCommerce
  - Real-time price and stock updates
  - Product code mapping between systems (using EanCode/SKU)

- **Inventory Management**
  - Real-time stock level synchronization
  - Pre-purchase stock verification
  - Automatic stock updates after successful orders

- **Order Processing**
  - Automatic order (WorkOrder) creation in Carfac ERP
  - Customer data synchronization
  - Enforced warehouse selection (Warehouse ID 1)

## Requirements

- WordPress 6.0 or higher
- WooCommerce 8.0 or higher
- PHP 8.1 or higher
- Carfac ERP API credentials (Username, Password, DealerCode)
- SSL certificate (for secure API communication)

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Activate the plugin
5. Go to sidebar under **DSN Carfac** for Settings

## Configuration

1. **API Settings**
   - Enter your Carfac Dealer Code
   - Enter your Carfac Username and Password
   - These credentials will be used to automatically retrieve the required JWT for API requests.

2. **Sync Settings**
   - Configure sync frequency (default: daily)

## Usage

### Product Synchronization
The plugin automatically syncs products daily from Carfac ERP to WooCommerce:
- Product SKU (EanCode) is used to match products.
- Prices and stock levels are updated automatically.

### Order Processing
When a customer places an order:
1. The plugin verifies stock availability in Carfac ERP.
2. Creates/Lookups the customer in Carfac by email.
3. Creates a WorkOrder in Carfac ERP.
4. Adds order lines to the WorkOrder.

## Support

For support, please:
1. Review the plugin's error logs in the settings page.
2. Contact DesignStudio Network with specific error messages.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

- Developed by DesignStudio Network.
- Carfac ERP API integration based on Carfac Cloud API.
