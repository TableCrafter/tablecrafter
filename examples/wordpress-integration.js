/**
 * TableCrafter.js WordPress Integration Example
 * This file shows how to integrate TableCrafter.js with WordPress/PHP backend
 * to replace the existing Gravity Tables plugin functionality
 */

/**
 * WordPress REST API Integration
 * 
 * This example assumes you have WordPress REST API endpoints set up:
 * - /wp-json/tablecrafter/v1/tables/{table_id}/data
 * - /wp-json/tablecrafter/v1/tables/{table_id}/entry
 * - /wp-json/tablecrafter/v1/lookup/{type}
 */

class WordPressTableCrafter {
    constructor(containerId, tableId, options = {}) {
        this.tableId = tableId;
        this.wpApiBase = options.wpApiBase || '/wp-json/tablecrafter/v1';
        this.nonce = options.nonce || window.tablecrafterNonce;
        
        // Default configuration for WordPress integration
        const defaultConfig = {
            api: {
                baseUrl: `${this.wpApiBase}/tables/${tableId}`,
                endpoints: {
                    data: '/data',
                    create: '/entry',
                    update: '/entry/{id}',
                    delete: '/entry/{id}',
                    lookup: `${this.wpApiBase}/lookup`
                },
                headers: {
                    'X-WP-Nonce': this.nonce,
                    'Content-Type': 'application/json'
                }
            },
            
            // WordPress user integration
            permissions: {
                enabled: true,
                view: options.userCanView || ['*'],
                edit: options.userCanEdit || ['administrator', 'editor'],
                delete: options.userCanDelete || ['administrator'],
                create: options.userCanCreate || ['administrator', 'editor'],
                ownOnly: options.ownOnly || false
            },
            
            // Integration with WordPress user system
            lookupIntegration: {
                users: {
                    endpoint: `${this.wpApiBase}/lookup/users`,
                    roleFilter: options.userRoleFilter || null
                },
                posts: {
                    endpoint: `${this.wpApiBase}/lookup/posts`,
                    postTypeFilter: options.postTypeFilter || 'post'
                },
                terms: {
                    endpoint: `${this.wpApiBase}/lookup/terms`,
                    taxonomyFilter: options.taxonomyFilter || 'category'
                }
            },
            
            // WordPress-specific features
            features: {
                gravityFormsIntegration: options.gravityFormsIntegration || false,
                userMetaFields: options.userMetaFields || [],
                customFields: options.customFields || [],
                shortcodeSupport: options.shortcodeSupport || true
            },
            
            ...options
        };
        
        this.table = new TableCrafter(containerId, defaultConfig);
        
        // Set WordPress user context
        if (window.currentUser) {
            this.table.setCurrentUser({
                id: window.currentUser.ID,
                name: window.currentUser.display_name,
                roles: window.currentUser.roles || [],
                capabilities: window.currentUser.allcaps || {}
            });
        }
        
        this.setupWordPressIntegration();
    }
    
    /**
     * Set up WordPress-specific integrations
     */
    setupWordPressIntegration() {
        // Override API requests to handle WordPress specifics
        const originalApiRequest = this.table.apiRequest.bind(this.table);
        
        this.table.apiRequest = async (endpoint, options = {}) => {
            // Add WordPress nonce to all requests
            if (!options.headers) options.headers = {};
            options.headers['X-WP-Nonce'] = this.nonce;
            
            try {
                return await originalApiRequest(endpoint, options);
            } catch (error) {
                // Handle WordPress-specific errors
                if (error.message.includes('403')) {
                    throw new Error('Permission denied. Please check your user permissions.');
                }
                if (error.message.includes('invalid_nonce')) {
                    throw new Error('Security token expired. Please refresh the page.');
                }
                throw error;
            }
        };
        
        // WordPress media integration
        this.setupMediaIntegration();
        
        // Gravity Forms integration
        if (this.table.config.features.gravityFormsIntegration) {
            this.setupGravityFormsIntegration();
        }
        
        // WordPress user lookup integration
        this.setupUserLookupIntegration();
    }
    
    /**
     * Set up WordPress media library integration
     */
    setupMediaIntegration() {
        // Add media upload capability to file fields
        this.table.createMediaUploadField = (column) => {
            const container = document.createElement('div');
            container.className = 'wp-media-upload';
            
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'wp-media-url';
            input.placeholder = 'Select media...';
            input.readonly = true;
            
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button wp-media-select';
            button.textContent = 'Select Media';
            
            button.addEventListener('click', () => {
                // WordPress media library integration
                if (window.wp && window.wp.media) {
                    const mediaUploader = window.wp.media({
                        title: 'Select Media',
                        button: { text: 'Select' },
                        multiple: false
                    });
                    
                    mediaUploader.on('select', () => {
                        const attachment = mediaUploader.state().get('selection').first().toJSON();
                        input.value = attachment.url;
                        input.dispatchEvent(new Event('change'));
                    });
                    
                    mediaUploader.open();
                }
            });
            
            container.appendChild(input);
            container.appendChild(button);
            return container;
        };
    }
    
    /**
     * Set up Gravity Forms integration
     */
    setupGravityFormsIntegration() {
        // Extend table to work with Gravity Forms entries
        this.table.loadGravityFormsEntries = async (formId) => {
            const response = await this.table.apiRequest(`/gravity-forms/${formId}/entries`);
            this.table.setData(response.entries || response);
        };
        
        // Add Gravity Forms field type support
        this.table.renderGravityField = (field, value) => {
            switch (field.type) {
                case 'select':
                case 'radio':
                    return this.renderGravityChoiceField(field, value);
                case 'checkbox':
                    return this.renderGravityCheckboxField(field, value);
                case 'date':
                    return this.renderGravityDateField(field, value);
                case 'fileupload':
                    return this.renderGravityFileField(field, value);
                default:
                    return value;
            }
        };
    }
    
    /**
     * Set up WordPress user lookup integration
     */
    setupUserLookupIntegration() {
        // Enhanced user lookup with WordPress roles and meta
        this.table.loadWordPressUsers = async (roleFilter = null) => {
            const endpoint = `/lookup/users${roleFilter ? `?role=${roleFilter}` : ''}`;
            const users = await this.table.apiRequest(endpoint);
            
            return users.map(user => ({
                id: user.ID,
                name: user.display_name,
                email: user.user_email,
                role: user.roles[0] || 'subscriber',
                avatar: user.avatar_urls ? user.avatar_urls['48'] : null,
                meta: user.meta || {}
            }));
        };
        
        // WordPress post lookup
        this.table.loadWordPressPosts = async (postType = 'post') => {
            const posts = await this.table.apiRequest(`/lookup/posts?post_type=${postType}`);
            
            return posts.map(post => ({
                id: post.ID,
                name: post.post_title,
                url: post.guid,
                status: post.post_status,
                date: post.post_date
            }));
        };
    }
    
    /**
     * Create WordPress shortcode output
     */
    generateShortcode(attributes = {}) {
        const attrs = Object.entries(attributes)
            .map(([key, value]) => `${key}="${value}"`)
            .join(' ');
            
        return `[tablecrafter table_id="${this.tableId}" ${attrs}]`;
    }
    
    /**
     * Initialize from WordPress shortcode attributes
     */
    static fromShortcode(containerId, shortcodeAtts) {
        const tableId = shortcodeAtts.table_id;
        const options = {
            pageSize: parseInt(shortcodeAtts.page_size) || 25,
            sortable: shortcodeAtts.sortable !== 'false',
            filterable: shortcodeAtts.filterable !== 'false',
            exportable: shortcodeAtts.exportable === 'true',
            editable: shortcodeAtts.editable === 'true',
            userCanView: shortcodeAtts.user_can_view ? shortcodeAtts.user_can_view.split(',') : ['*'],
            userCanEdit: shortcodeAtts.user_can_edit ? shortcodeAtts.user_can_edit.split(',') : ['administrator'],
            ownOnly: shortcodeAtts.own_only === 'true',
            gravityFormsIntegration: shortcodeAtts.gravity_forms === 'true'
        };
        
        return new WordPressTableCrafter(containerId, tableId, options);
    }
    
    /**
     * Get the underlying TableCrafter instance
     */
    getTable() {
        return this.table;
    }
    
    /**
     * Render the table
     */
    async render() {
        try {
            await this.table.loadDataFromAPI();
            this.table.render();
        } catch (error) {
            console.error('Failed to load WordPress table:', error);
            
            // Show user-friendly error message
            const container = document.querySelector(this.table.container);
            if (container) {
                container.innerHTML = `
                    <div class="wp-table-error">
                        <p><strong>Error loading table:</strong> ${error.message}</p>
                        <p>Please check your permissions and try again.</p>
                    </div>
                `;
            }
        }
    }
}

/**
 * WordPress Plugin Integration
 * 
 * Replace the existing Gravity Tables plugin functionality
 */

// Global TableCrafter WordPress integration
window.TableCrafterWP = {
    tables: new Map(),
    
    /**
     * Initialize table from WordPress admin or frontend
     */
    init: function(containerId, tableId, options = {}) {
        const table = new WordPressTableCrafter(containerId, tableId, options);
        this.tables.set(tableId, table);
        return table;
    },
    
    /**
     * Get existing table instance
     */
    getTable: function(tableId) {
        return this.tables.get(tableId);
    },
    
    /**
     * Initialize all tables on page
     */
    initAll: function() {
        document.querySelectorAll('[data-tablecrafter-id]').forEach(container => {
            const tableId = container.dataset.tablecrafterId;
            const options = JSON.parse(container.dataset.tablecrafterOptions || '{}');
            
            const table = this.init(container, tableId, options);
            table.render();
        });
    },
    
    /**
     * WordPress admin integration
     */
    admin: {
        /**
         * Table builder integration
         */
        initBuilder: function(containerId, existingConfig = {}) {
            // This would integrate with WordPress admin table builder
            return new TableCrafterBuilder(containerId, existingConfig);
        },
        
        /**
         * Save table configuration to WordPress
         */
        saveTableConfig: async function(tableId, config) {
            const response = await fetch(`/wp-json/tablecrafter/v1/tables/${tableId}/config`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.tablecrafterNonce
                },
                body: JSON.stringify(config)
            });
            
            if (!response.ok) {
                throw new Error('Failed to save table configuration');
            }
            
            return await response.json();
        }
    }
};

/**
 * jQuery integration for WordPress themes
 */
if (window.jQuery) {
    (function($) {
        $.fn.tablecrafter = function(options = {}) {
            return this.each(function() {
                const $this = $(this);
                const tableId = $this.data('table-id') || options.tableId;
                
                if (!tableId) {
                    console.error('TableCrafter: table ID is required');
                    return;
                }
                
                const table = window.TableCrafterWP.init(this, tableId, options);
                table.render();
                
                // Store instance on element
                $this.data('tablecrafter-instance', table);
            });
        };
        
        // Auto-initialize on document ready
        $(document).ready(function() {
            $('.tablecrafter-table').tablecrafter();
        });
        
    })(window.jQuery);
}

/**
 * WordPress Hooks Integration
 * 
 * These hooks allow WordPress plugins to extend TableCrafter functionality
 */
window.TableCrafterHooks = {
    filters: new Map(),
    actions: new Map(),
    
    /**
     * Add a filter hook
     */
    addFilter: function(tag, callback, priority = 10) {
        if (!this.filters.has(tag)) {
            this.filters.set(tag, []);
        }
        this.filters.get(tag).push({ callback, priority });
        this.filters.get(tag).sort((a, b) => a.priority - b.priority);
    },
    
    /**
     * Apply filters
     */
    applyFilters: function(tag, value, ...args) {
        const filters = this.filters.get(tag) || [];
        return filters.reduce((acc, filter) => {
            return filter.callback(acc, ...args);
        }, value);
    },
    
    /**
     * Add an action hook
     */
    addAction: function(tag, callback, priority = 10) {
        if (!this.actions.has(tag)) {
            this.actions.set(tag, []);
        }
        this.actions.get(tag).push({ callback, priority });
        this.actions.get(tag).sort((a, b) => a.priority - b.priority);
    },
    
    /**
     * Do action
     */
    doAction: function(tag, ...args) {
        const actions = this.actions.get(tag) || [];
        actions.forEach(action => {
            action.callback(...args);
        });
    }
};

/**
 * Example WordPress plugin integration
 */

// Hook into table initialization
window.TableCrafterHooks.addFilter('tablecrafter_config', function(config, tableId) {
    // Add custom WordPress-specific configuration
    config.wordpress = {
        tableId: tableId,
        adminUrl: window.tablecrafterAdmin?.adminUrl,
        userCan: window.tablecrafterAdmin?.userCan || {}
    };
    
    return config;
});

// Hook into data loading
window.TableCrafterHooks.addAction('tablecrafter_data_loaded', function(table, data) {
    // Custom processing after data is loaded
    console.log(`Loaded ${data.length} entries for table ${table.tableId}`);
});

// Hook into editing
window.TableCrafterHooks.addAction('tablecrafter_entry_edited', function(table, editEvent) {
    // Custom processing after entry is edited
    console.log('Entry edited:', editEvent);
});

/**
 * Export for use in WordPress themes and plugins
 */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { WordPressTableCrafter, TableCrafterWP: window.TableCrafterWP };
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.TableCrafterWP.initAll();
    });
} else {
    window.TableCrafterWP.initAll();
}