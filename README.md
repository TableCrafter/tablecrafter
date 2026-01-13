# TableCrafter.js

Advanced data table library with **complete WordPress plugin parity** - zero dependencies, mobile-first, API-ready.

> **WordPress Plugin Replacement**: Drop-in replacement for WordPress Gravity Tables with 100% feature parity plus modern enhancements.

📖 **[Complete Documentation](https://tablecrafter.github.io/docs/)** | 🎯 **[Live Examples](https://tablecrafter.github.io/docs/examples/basic)** | 🔗 **[API Reference](https://tablecrafter.github.io/docs/api/tablecrafter)**

## WordPress Parity Features

- 🔍 **Advanced Filtering** - Auto-detection, multiselect, date ranges, number ranges
- ✨ **Smart Data Formatting** - Auto-format ISO dates, URLs, emails, and booleans into rich HTML
- ⚡ **Bulk Operations** - Multi-row selection, progress indicators, custom actions
- 🔗 **Lookup Fields** - API-driven dropdowns with caching and role-based filtering
- 🛡️ **Permission System** - Role-based access control with user context
- 📱 **Enhanced Mobile Cards** - Expandable sections with field visibility controls
- 💾 **State Persistence** - Remember filters, sorting, pagination across sessions
- 🔄 **API Integration** - RESTful CRUD operations with authentication
- ✏️ **Advanced Editing** - Inline editing with lookup dropdowns and validation

## Core Features

- 🚀 **Zero Dependencies** - Pure vanilla JavaScript (45KB bundle)
- 📱 **Mobile First** - Touch-optimized with expandable card layouts
- 🌐 **Framework Agnostic** - React, Vue, Angular, WordPress, or vanilla HTML
- 🔧 **Enterprise Ready** - Permissions, bulk operations, API integration
- 🎨 **Highly Customizable** - CSS variables, themes, component styling
- 📊 **Performance Optimized** - Virtual scrolling, efficient rendering

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

### Basic Usage
```html
<div id="my-table"></div>

<script>
const data = [
    { id: 1, name: 'John Doe', email: 'john@example.com', department: 'Engineering' },
    { id: 2, name: 'Jane Smith', email: 'jane@example.com', department: 'Design' },
    { id: 3, name: 'Bob Johnson', email: 'bob@example.com', department: 'Management' }
];

const table = new TableCrafter('#my-table', {
    data: data,
    columns: [
        { field: 'id', label: 'ID' },
        { field: 'name', label: 'Name', editable: true },
        { field: 'email', label: 'Email', editable: true },
        { field: 'department', label: 'Department', editable: true }
    ],
    editable: true,
    responsive: true,
    pagination: true
});

table.render();
</script>
```

### WordPress Parity Features
```javascript
const advancedTable = new TableCrafter('#advanced-table', {
    data: '/api/employees',
    
    // Advanced filtering with auto-detection
    filters: {
        advanced: true,
        autoDetect: true,
        showClearAll: true
    },
    
    // Bulk operations
    bulk: {
        enabled: true,
        operations: ['delete', 'export', 'promote']
    },
    
    // API integration
    api: {
        baseUrl: '/api/employees',
        authentication: { type: 'bearer', token: 'jwt-token' }
    },
    
    // Permission system
    permissions: {
        enabled: true,
        edit: ['admin', 'manager'],
        delete: ['admin']
    },
    
    // State persistence
    state: { persist: true }
});

// Set user context for permissions
table.setCurrentUser({ id: 1, roles: ['admin'] });
table.render();
```

📖 **[View Complete Documentation](https://tablecrafter.github.io/docs/guide/getting-started)**

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

## WordPress Plugin Migration

Migrating from WordPress Gravity Tables? TableCrafter provides 100% feature parity:

| WordPress Feature | TableCrafter.js | Status |
|-------------------|-----------------|---------|
| Advanced Filtering | ✅ Auto-detection | Enhanced |
| Bulk Operations | ✅ Progress indicators | Enhanced |
| Mobile Cards | ✅ Expandable sections | Enhanced |
| Lookup Fields | ✅ API integration | Enhanced |
| Permissions | ✅ Role-based access | Enhanced |
| Inline Editing | ✅ Dropdown lookups | Enhanced |

📖 **[Migration Guide](https://tablecrafter.github.io/docs/guide/wordpress)**

## Use Cases

- **🏢 WordPress Plugin Replacement** - Replace Gravity Tables installations
- **📊 Enterprise Dashboards** - Role-based data access with permissions  
- **📋 SaaS Admin Interfaces** - Multi-tenant user management
- **📱 Mobile-First Applications** - Touch-optimized data tables
- **🔗 API-Driven Applications** - Dynamic lookup fields, real-time updates

## Framework Integration

- **[React](https://tablecrafter.github.io/docs/guide/react)** - React component wrapper
- **[Vue.js](https://tablecrafter.github.io/docs/guide/vue)** - Vue component integration  
- **[Angular](https://tablecrafter.github.io/docs/guide/angular)** - Angular component setup
- **[WordPress](https://tablecrafter.github.io/docs/guide/wordpress)** - WordPress plugin integration

## Community

- **[📖 Documentation](https://tablecrafter.github.io/docs/)** - Complete guides and API reference
- **[🎯 Examples](https://tablecrafter.github.io/docs/examples/basic)** - Live demos and code samples
- **[🐛 Issues](https://github.com/TableCrafter/tablecrafter/issues)** - Bug reports and feature requests
- **[💬 Discussions](https://github.com/TableCrafter/tablecrafter/discussions)** - Community support

## License

MIT License - see [LICENSE](LICENSE) file for details.

---

**TableCrafter.js** - Advanced data table library with complete WordPress plugin parity, zero dependencies, and mobile-first design.