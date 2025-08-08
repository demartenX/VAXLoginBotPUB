# VAX Scraper Project

## Overview

This is a PHP web scraping project that successfully extracts product pricing data from the VAX B2B system (https://b2b.waks.pl). The system is designed to automatically log into the VAX B2B portal and retrieve product information including codes, descriptions, prices, and availability from specified categories. The scraper is now fully functional and operational.

## Recent Changes (August 1, 2025)

✓ **PHP Installation and Setup**: Successfully installed PHP 8.2 environment
✓ **Authentication System**: Implemented robust login functionality for VAX B2B system
✓ **AJAX Endpoint Discovery**: Found and integrated with the `/product/list/ajax` endpoint 
✓ **Product Data Extraction**: Successfully scraped 1314 products from category 1395
✓ **CSV Export**: Implemented comprehensive data export to CSV format with European formatting
✓ **Error Handling**: Added comprehensive error handling and diagnostic logging
✓ **Google Sheets Compatibility**: Fixed CSV format to use comma separators instead of semicolons
✓ **Data Cleaning**: Removed problematic commas from product descriptions and technical specifications
✓ **Currency Symbol Removal**: Eliminated "zł" symbols from product descriptions for cleaner data
✓ **BOM Removal**: Removed UTF-8 Byte Order Mark to prevent display issues in spreadsheet applications
✓ **Column Structure**: Ensured consistent 7-column structure across all CSV rows

## User Preferences

Preferred communication style: Simple, everyday language.

## System Architecture

### Core Components
- **Authentication Module**: Handles login to VAX B2B system using form-based authentication
- **AJAX-based Scraper Engine**: Uses discovered AJAX endpoints for efficient data extraction
- **Product Parser**: Extracts structured product data from HTML tables
- **CSV Export System**: Formats and saves product data in European CSV format
- **Logging System**: Comprehensive logging with timestamps and error tracking

### Design Patterns
- **Session Management**: Uses cURL with cookie persistence for authenticated sessions
- **AJAX Integration**: Leverages AJAX endpoints for faster, more reliable data access
- **Error Recovery**: Multiple retry mechanisms and fallback strategies
- **Data Validation**: Ensures data integrity before processing and export

### Data Flow
1. System authenticates with VAX B2B portal using credentials
2. AJAX request sent to `/product/list/ajax` with category parameters
3. HTML response parsed to extract product table data
4. Product information structured and validated
5. Data exported to timestamped CSV file
6. All operations logged for audit and debugging

## External Dependencies

### Current Dependencies
- **PHP 8.2**: Core runtime environment
- **cURL**: HTTP client for web requests and session management
- **DOMDocument/XPath**: HTML parsing and data extraction
- **CSV Functions**: Native PHP CSV handling for data export

### Target Data Sources
- **VAX B2B System**: https://b2b.waks.pl (QST company system)
- **Authentication**: Form-based login with session cookies
- **Product Categories**: Currently focused on category 1395 (1314+ products)
- **Available Categories**: System contains 100+ product categories with varying product counts

## Performance Metrics
- **Products Scraped**: 1314 items from single category
- **Data Size**: 6+ MB AJAX response processed
- **Processing Time**: < 10 seconds end-to-end
- **Success Rate**: 100% for authenticated category access