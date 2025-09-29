# TableCrafter.js Examples

This directory contains comprehensive examples demonstrating all advanced features implemented in TableCrafter.js, translated from the WordPress Gravity Tables plugin.

## 🚀 Quick Start

1. **Advanced Features Demo** (`advanced-features.html`)
   - Complete showcase of all new features
   - Interactive demo with real functionality
   - Feature comparison with WordPress plugin
   - Live configuration examples

2. **API Integration Demo** (`api-integration.html`)
   - REST API integration with mock server
   - CRUD operations demonstration
   - Authentication and error handling
   - Real-time data updates

3. **WordPress Integration** (`wordpress-integration.js`)
   - Complete WordPress plugin replacement
   - Gravity Forms integration
   - WordPress user/role system integration
   - Shortcode support and admin integration

## 📋 Feature Implementation Status

### ✅ Completed Features

| Feature | WordPress Plugin | TableCrafter.js | Status |
|---------|------------------|-----------------|---------|
| **Advanced Filtering** | ✅ Multi-select, date/number ranges | ✅ Auto-detection, all filter types | ✅ **100% Complete** |
| **Mobile Card Layout** | ✅ Expandable cards, field visibility | ✅ Responsive breakpoints, touch optimized | ✅ **100% Complete** |
| **Bulk Operations** | ✅ Select, delete, export, edit | ✅ Full bulk framework + custom actions | ✅ **100% Complete** |
| **Inline Editing** | ✅ Click to edit, validation | ✅ Enhanced with lookup dropdowns | ✅ **100% Complete** |
| **Add New Entries** | ✅ Modal creation, validation | ✅ Dynamic forms, validation engine | ✅ **100% Complete** |
| **Lookup Fields** | ✅ Users, posts, custom tables | ✅ API-driven, cached, filterable | ✅ **100% Complete** |
| **Permission System** | ✅ Role-based, field-level | ✅ Configurable, action-based | ✅ **100% Complete** |
| **State Persistence** | ✅ Server-side state | ✅ Client-side localStorage/session | ✅ **100% Complete** |
| **API Integration** | ✅ WordPress hooks/filters | ✅ RESTful APIs, authentication | ✅ **100% Complete** |

### 🔄 WordPress Integration Features

- **Gravity Forms Integration**: Connect with existing form entries
- **WordPress User System**: Role-based permissions, user lookups
- **Media Library**: File upload integration
- **Shortcode Support**: Easy embedding in posts/pages  
- **Admin Interface**: Table builder and configuration
- **Hooks & Filters**: Extensibility for other plugins

## 🎯 Usage Examples

### Basic Implementation

```javascript
const table = new TableCrafter('#my-table', {
    data: myData,
    columns: [
        { field: 'id', label: 'ID' },
        { field: 'name', label: 'Name', editable: true },
        { field: 'email', label: 'Email', editable: true }
    ],
    editable: true,
    pagination: true,
    filterable: true
});

table.render();
```

### Advanced Configuration

```javascript
const advancedTable = new TableCrafter('#advanced-table', {
    // Auto-detect filter types from data
    filters: {
        advanced: true,
        autoDetect: true,
        showClearAll: true
    },
    
    // Bulk operations with custom actions
    bulk: {
        enabled: true,
        operations: ['delete', 'export', 'custom-action']
    },
    
    // Responsive design with field visibility
    responsive: {
        fieldVisibility: {
            mobile: { showFields: ['name', 'status'] },
            tablet: { showFields: ['name', 'email', 'status'] }
        }
    },
    
    // API integration
    api: {
        baseUrl: '/api/users',
        authentication: { type: 'bearer', token: 'jwt-token' }
    },
    
    // Permission system
    permissions: {
        enabled: true,
        edit: ['admin', 'editor'],
        delete: ['admin']
    }
});
```

### WordPress Integration

```javascript
// Replace existing Gravity Tables plugin
const wpTable = new WordPressTableCrafter('#wp-table', tableId, {
    gravityFormsIntegration: true,
    userRoleFilter: 'driver',
    ownOnly: true
});

wpTable.render();
```

### Lookup Fields

```javascript
const tableWithLookups = new TableCrafter('#lookup-table', {
    columns: [
        {
            field: 'user_id',
            label: 'Assigned User',
            editable: true,
            lookup: {
                url: '/api/users',
                valueField: 'id',
                displayField: 'name',
                filter: { role: 'driver' }
            }
        }
    ]
});
```

## 🛠️ Development Features

### State Management

- **Persistent Filters**: Automatically save and restore filter states
- **Pagination Memory**: Remember current page across sessions
- **Selection State**: Maintain bulk selections during navigation
- **Sort Preferences**: Remember column sorting preferences

### Performance Optimizations

- **Lookup Caching**: Cache API responses for faster lookups
- **Efficient Rendering**: Only re-render changed elements
- **Virtual Scrolling**: Handle large datasets efficiently
- **Lazy Loading**: Load data as needed

### Error Handling

- **API Resilience**: Graceful handling of network failures
- **Validation Feedback**: Clear error messages for users
- **Fallback Modes**: Offline functionality when possible
- **Debug Mode**: Comprehensive logging for development

## 🔧 Integration Guides

### React Integration

```jsx
import TableCrafter from 'tablecrafter';

const ReactTable = ({ data, onEdit }) => {
    const tableRef = useRef();
    
    useEffect(() => {
        const table = new TableCrafter(tableRef.current, {
            data,
            onEdit
        });
        table.render();
        
        return () => table.destroy();
    }, [data]);
    
    return <div ref={tableRef}></div>;
};
```

### Vue.js Integration

```vue
<template>
    <div ref="tableContainer"></div>
</template>

<script>
import TableCrafter from 'tablecrafter';

export default {
    props: ['data'],
    mounted() {
        this.table = new TableCrafter(this.$refs.tableContainer, {
            data: this.data
        });
        this.table.render();
    },
    beforeDestroy() {
        this.table.destroy();
    }
};
</script>
```

### Angular Integration

```typescript
import { Component, ElementRef, Input, OnInit, OnDestroy } from '@angular/core';
import TableCrafter from 'tablecrafter';

@Component({
    selector: 'app-table',
    template: '<div></div>'
})
export class TableComponent implements OnInit, OnDestroy {
    @Input() data: any[];
    private table: TableCrafter;
    
    constructor(private elementRef: ElementRef) {}
    
    ngOnInit() {
        this.table = new TableCrafter(this.elementRef.nativeElement, {
            data: this.data
        });
        this.table.render();
    }
    
    ngOnDestroy() {
        this.table.destroy();
    }
}
```

## 📖 API Documentation

See the main documentation for complete API reference:
- [Configuration Options](../docs/configuration.md)
- [API Methods](../docs/api-methods.md)
- [Event Callbacks](../docs/events.md)
- [Styling Guide](../docs/styling.md)

## 🎨 Customization

### CSS Custom Properties

```css
:root {
    --tc-primary-color: #3498db;
    --tc-border-color: #e1e5e9;
    --tc-font-family: 'Inter', sans-serif;
    --tc-border-radius: 8px;
}
```

### Custom Themes

```javascript
const table = new TableCrafter('#table', {
    theme: 'dark', // or 'light', 'custom'
    customCSS: '/path/to/custom.css'
});
```

## 🧪 Testing

Run the examples:

1. Start a local server:
   ```bash
   cd tablecrafter-js
   python -m http.server 8000
   ```

2. Open examples:
   - http://localhost:8000/examples/advanced-features.html
   - http://localhost:8000/examples/api-integration.html

## 🚀 Production Deployment

### CDN Usage

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tablecrafter@latest/dist/tablecrafter.css">
<script src="https://cdn.jsdelivr.net/npm/tablecrafter@latest/dist/tablecrafter.js"></script>
```

### NPM Installation

```bash
npm install tablecrafter
```

```javascript
import TableCrafter from 'tablecrafter';
import 'tablecrafter/dist/tablecrafter.css';
```

## 🤝 Contributing

1. Fork the repository
2. Create feature branch: `git checkout -b feature/amazing-feature`
3. Commit changes: `git commit -m 'Add amazing feature'`
4. Push to branch: `git push origin feature/amazing-feature`
5. Open Pull Request

## 📄 License

MIT License - see LICENSE file for details.

---

**TableCrafter.js** - A complete WordPress Gravity Tables plugin replacement with advanced features, mobile-first design, and framework-agnostic architecture.