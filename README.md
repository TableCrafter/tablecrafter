# TableCrafter.js

A lightweight, mobile-responsive JavaScript data table library with inline editing capabilities.

## Features

- 🚀 **Zero Dependencies** - Pure vanilla JavaScript
- 📱 **Mobile Responsive** - Automatic table ↔ cards responsive switching
- ✏️ **Inline Editing** - Click any cell to edit data with validation
- 🔄 **Data Loading** - Support for arrays, URLs, and async data sources
- 📊 **Sorting** - Click column headers to sort data
- 📋 **Advanced Filtering** - Real-time text filtering with multiple simultaneous filters
- 📄 **Pagination** - Client-side pagination with configurable page sizes
- 📤 **CSV Export** - RFC 4180 compliant export with filtered data options
- 🎨 **Customizable** - Extensive configuration options
- 🔧 **Event Callbacks** - Hook into data changes and user interactions

## Installation

### NPM
```bash
npm install tablecrafter
```

### CDN
```html
<script src="https://unpkg.com/tablecrafter@latest/dist/tablecrafter.min.js"></script>
```

## Quick Start

```html
<div id="my-table"></div>

<script>
const data = [
    { id: 1, name: 'John Doe', email: 'john@example.com', role: 'Developer' },
    { id: 2, name: 'Jane Smith', email: 'jane@example.com', role: 'Designer' },
    { id: 3, name: 'Bob Johnson', email: 'bob@example.com', role: 'Manager' }
];

const table = new TableCrafter('#my-table', {
    data: data,
    columns: [
        { field: 'id', label: 'ID' },
        { field: 'name', label: 'Name', editable: true },
        { field: 'email', label: 'Email', editable: true },
        { field: 'role', label: 'Role', editable: true }
    ],
    editable: true,
    responsive: true,
    pagination: true,
    pageSize: 10
});

table.render();
</script>
```

## Configuration Options

```javascript
const config = {
    data: [],                    // Array of objects or URL string
    columns: [],                 // Column definitions (required)
    editable: false,             // Enable inline editing
    responsive: true,            // Enable mobile card view
    mobileBreakpoint: 768,       // Pixel width for mobile view
    pagination: false,           // Enable pagination
    pageSize: 25,               // Rows per page
    sortable: true,             // Enable column sorting
    filterable: true,           // Enable filtering
    exportable: false,          // Enable CSV export
    exportFilename: 'export.csv', // Default export filename
    onEdit: function(change) {  // Callback for edits
        console.log('Cell edited:', change);
    },
    onSort: function(column, direction) {  // Callback for sorting
        console.log('Sorted by:', column, direction);
    }
};
```

## Column Configuration

```javascript
const columns = [
    {
        field: 'id',           // Data field name (required)
        label: 'ID',           // Display label (required)
        editable: false,       // Can this column be edited?
        sortable: true,        // Can this column be sorted?
        filterable: true       // Can this column be filtered?
    },
    {
        field: 'name',
        label: 'Full Name',
        editable: true,
        sortable: true
    }
];
```

## API Methods

### Data Management
- `table.render()` - Render/re-render the table
- `table.getData()` - Get current filtered data
- `table.setData(data)` - Replace table data
- `table.addRow(rowData)` - Add a new row
- `table.removeRow(index)` - Remove row by index
- `table.updateRow(index, rowData)` - Update specific row

### Filtering & Sorting
- `table.setFilter(column, value)` - Set filter for specific column
- `table.clearFilters()` - Clear all filters
- `table.sort(column, direction)` - Sort by column ('asc' or 'desc')

### Export
- `table.exportCSV()` - Export current data to CSV
- `table.exportCSV(filename)` - Export with custom filename

### Utilities
- `table.destroy()` - Clean up and remove event listeners
- `table.refresh()` - Refresh table display

## Events

TableCrafter triggers custom events that you can listen to:

```javascript
const table = new TableCrafter('#my-table', config);

// Listen for cell edits
table.on('cellEdit', function(event) {
    console.log('Cell edited:', event.detail);
});

// Listen for row selection
table.on('rowSelect', function(event) {
    console.log('Row selected:', event.detail);
});

// Listen for sort changes
table.on('sort', function(event) {
    console.log('Table sorted:', event.detail);
});
```

## Styling

TableCrafter uses CSS classes with the `tc-` prefix. You can customize the appearance by overriding these classes:

```css
/* Main table wrapper */
.tc-wrapper { }

/* Table styles */
.tc-table { }
.tc-table th { }
.tc-table td { }

/* Mobile card view */
.tc-cards-container { }
.tc-card { }

/* Filters */
.tc-filters { }
.tc-filter { }

/* Pagination */
.tc-pagination { }

/* Edit mode */
.tc-editable { }
.tc-edit-input { }
```

## Browser Support

- **Modern Browsers**: Chrome 60+, Firefox 55+, Safari 12+, Edge 79+
- **Mobile**: iOS Safari 12+, Chrome Mobile 60+
- **Legacy**: IE11+ (with polyfills for fetch API and CSS Grid)

## Examples

### Loading Data from URL
```javascript
const table = new TableCrafter('#table', {
    data: '/api/users',  // URL endpoint
    columns: [
        { field: 'name', label: 'Name' },
        { field: 'email', label: 'Email' }
    ]
});
```

### Custom Edit Validation
```javascript
const table = new TableCrafter('#table', {
    data: users,
    columns: columns,
    onEdit: function(change) {
        if (change.field === 'email' && !change.newValue.includes('@')) {
            alert('Invalid email address');
            return false; // Reject the change
        }
        return true; // Accept the change
    }
});
```

## Development

```bash
# Install dependencies
npm install

# Run tests
npm test

# Run tests in watch mode
npm run test:watch

# Run tests with coverage
npm run test:coverage

# Build for production
npm run build
```

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Add tests for new features
4. Ensure all tests pass (`npm test`)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

## License

MIT License - see [LICENSE](LICENSE) file for details.

---

**TableCrafter** - Craft beautiful, responsive data tables with ease.