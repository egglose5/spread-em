# Spread Em - Architecture & Design Philosophy

## Core Concept

**Spread Em is a practical spreadsheet editor that removes the middle man from the WooCommerce export/import process.**

Instead of users having to:
1. Export products to CSV
2. Open CSV in Excel/Sheets
3. Modify data
4. Save and import back into WooCommerce

Spread Em lets users edit everything inline in the WordPress admin.

## Data Structure Principle

**All data modifications should follow the same path as WooCommerce's CSV export/import.**

This means:
- The data format mirrors `WC_Product_CSV_Exporter` output exactly
- Fields match WooCommerce's standard CSV columns
- Saving uses the same WooCommerce Product API calls that the CSV importer uses

This approach ensures:
- **Compatibility**: Data format is proven by WooCommerce's own export tool
- **Reliability**: No guessing at internal APIs or undocumented structures
- **Maintainability**: Future WooCommerce updates won't break us

## Adding Features

When adding new features (like variations, custom fields, etc.):

1. **Check WC_Product_CSV_Exporter** - How does it export this data?
2. **Mirror that format** - Use the exact same field names and structure
3. **Use WooCommerce Product API** - Save via `WC_Product->set_*()` methods

Example: If WooCommerce exports variations as separate rows with parent ID, load them the same way.

## Current Data Flow

```
Backend (PHP)
├── Load selected products via WP_Query
├── Convert to rows via product_to_row() 
│   ├── Mirrors WC CSV export format
│   ├── Uses WC_Product getter methods
│   └── Returns flat array matching CSV columns
└── Send to frontend as JSON

Frontend (JavaScript)
├── Build editable HTML table from rows
├── Track changes via change events
└── Send only modified rows back to server

AJAX Handler (PHP)
├── Receive changed rows JSON
├── For each row: apply_row_to_product()
│   ├── Uses WC_Product setter methods
│   ├── Same method as CSV importer uses
│   └── Save via $product->save()
└── Return success/error status
```

## Why This Works

By following the WooCommerce CSV export/import pattern:
- ✅ Users can still use CSV export directly if needed
- ✅ Data is always in "WooCommerce format"
- ✅ Variations, attributes, custom fields all work the same way
- ✅ No custom code needed for complex data structures
- ✅ Future-proof against WooCommerce updates
