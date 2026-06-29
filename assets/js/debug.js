/**
 * Gravity Tables Frontend Debug System
 * 
 * Provides enhanced console logging with categories and filtering.
 * Integrates with the backend debug system for comprehensive debugging.
 * 
 * @package GravityTables
 * @version 1.0.0
 */

(function($) {
    'use strict';
    
    /**
     * GT Debug Frontend Class
     */
    window.GTDebugFrontend = {
        
        // Configuration
        config: {
            enabled: false,
            categories: {},
            prefix: '[GT Debug]',
            colors: {
                ajax: '#2196F3',
                filtering: '#0073aa', 
                sorting: '#FF9800',
                lookup: '#9C27B0',
                permissions: '#F44336',
                frontend: '#00BCD4',
                conditional: '#795548',
                performance: '#607D8B',
                validation: '#0073aa',
                error: '#F44336',
                warning: '#FF9800',
                info: '#2196F3',
                success: '#0073aa'
            }
        },
        
        // Performance timers
        timers: {},
        
        /**
         * Initialize debug system
         */
        init: function() {
            if (typeof gtDebug !== 'undefined') {
                this.config.enabled = gtDebug.enabled || false;
                this.config.categories = gtDebug.categories || {};
                
                if (this.config.enabled) {
                    this.log('frontend', 'GT Debug Frontend initialized', this.config);
                    this.attachToWindow();
                    this.monitorAjaxRequests();
                    this.monitorTableEvents();
                }
            }
        },
        
        /**
         * Check if a category is enabled
         */
        isEnabled: function(category) {
            return this.config.enabled && 
                   (this.config.categories.all || this.config.categories[category]);
        },
        
        /**
         * Enhanced console logging with categories and styling
         */
        log: function(category, message, data, level) {
            if (!this.isEnabled(category)) {
                return;
            }
            
            level = level || 'log';
            const timestamp = new Date().toISOString().slice(11, 23);
            const color = this.config.colors[category] || this.config.colors[level] || '#333333';
            
            const styles = [
                `color: ${color}`,
                'font-weight: bold',
                'background: rgba(0,0,0,0.1)',
                'padding: 2px 6px',
                'border-radius: 3px'
            ].join(';');
            
            const prefix = `%c${this.config.prefix} [${category.toUpperCase()}] [${timestamp}]`;
            
            if (data !== undefined) {
                console.group(prefix, styles, message);
                if (typeof data === 'object') {
                    console.table ? console.table(data) : console.log(data);
                } else {
                    console.log('Data:', data);
                }
                console.groupEnd();
            } else {
                console[level](prefix, styles, message);
            }
            
            // Send to backend if needed
            this.sendToBackend(category, message, data, level);
        },
        
        /**
         * Convenience methods for different log levels
         */
        error: function(category, message, data) {
            this.log(category, message, data, 'error');
        },
        
        warn: function(category, message, data) {
            this.log(category, message, data, 'warn');
        },
        
        info: function(category, message, data) {
            this.log(category, message, data, 'info');
        },
        
        /**
         * Start a performance timer
         */
        startTimer: function(name, category) {
            category = category || 'performance';
            if (!this.isEnabled(category)) {
                return;
            }
            
            this.timers[name] = performance.now();
            this.log(category, `Timer started: ${name}`);
        },
        
        /**
         * End a performance timer
         */
        endTimer: function(name, category) {
            category = category || 'performance';
            if (!this.isEnabled(category) || !this.timers[name]) {
                return;
            }
            
            const elapsed = performance.now() - this.timers[name];
            delete this.timers[name];
            
            this.log(category, `Timer '${name}' completed in ${elapsed.toFixed(2)}ms`);
            return elapsed;
        },
        
        /**
         * Log AJAX requests
         */
        logAjax: function(action, requestData, responseData, status) {
            if (!this.isEnabled('ajax')) {
                return;
            }
            
            const data = {
                action: action,
                status: status,
                requestData: requestData,
                responseData: responseData,
                timestamp: new Date().toISOString()
            };
            
            const level = status === 'error' ? 'error' : 'log';
            this.log('ajax', `AJAX ${action} - ${status}`, data, level);
        },
        
        /**
         * Monitor jQuery AJAX requests
         */
        monitorAjaxRequests: function() {
            if (!this.isEnabled('ajax')) {
                return;
            }
            
            const self = this;
            
            // Monitor jQuery AJAX
            $(document).ajaxSend(function(event, jqXHR, settings) {
                if (settings.url.indexOf('admin-ajax.php') !== -1 && 
                    settings.data && self.hasGravityTablesAction(settings.data)) {
                    
                    const action = self.extractAjaxAction(settings.data);
                    self.log('ajax', `AJAX Request: ${action}`, {
                        url: settings.url,
                        data: self.convertDataForLogging(settings.data),
                        method: settings.type
                    });
                }
            });
            
            $(document).ajaxComplete(function(event, jqXHR, settings) {
                if (settings.url.indexOf('admin-ajax.php') !== -1 && 
                    settings.data && self.hasGravityTablesAction(settings.data)) {
                    
                    const action = self.extractAjaxAction(settings.data);
                    const status = jqXHR.status >= 200 && jqXHR.status < 300 ? 'success' : 'error';
                    
                    try {
                        const response = JSON.parse(jqXHR.responseText);
                        self.logAjax(action, self.convertDataForLogging(settings.data), response, status);
                    } catch (e) {
                        self.logAjax(action, self.convertDataForLogging(settings.data), jqXHR.responseText, status);
                    }
                }
            });
        },
        
        /**
         * Convert data for safe logging (handles FormData)
         */
        convertDataForLogging: function(data) {
            if (typeof data === 'string') {
                return data;
            } else if (data instanceof FormData) {
                // Convert FormData to a regular object for logging
                const obj = {};
                for (let pair of data.entries()) {
                    obj[pair[0]] = pair[1];
                }
                return obj;
            }
            return data;
        },
        
        /**
         * Check if the request data contains Gravity Tables actions
         */
        hasGravityTablesAction: function(data) {
            if (typeof data === 'string') {
                return data.indexOf('gt_') !== -1;
            } else if (data instanceof FormData) {
                // Check if any of the FormData values contain 'gt_'
                for (let pair of data.entries()) {
                    if (pair[0] === 'action' && pair[1].indexOf('gt_') !== -1) {
                        return true;
                    }
                }
                return false;
            }
            return false;
        },
        
        /**
         * Extract AJAX action from request data
         */
        extractAjaxAction: function(data) {
            if (typeof data === 'string') {
                const match = data.match(/action=([^&]+)/);
                return match ? match[1] : 'unknown';
            } else if (data instanceof FormData) {
                for (let pair of data.entries()) {
                    if (pair[0] === 'action') {
                        return pair[1];
                    }
                }
                return 'unknown';
            }
            return 'unknown';
        },
        
        /**
         * Monitor table-specific events
         */
        monitorTableEvents: function() {
            const self = this;
            
            // Monitor filter changes
            $(document).on('change', '.gt-filter-input', function() {
                if (self.isEnabled('filtering')) {
                    const fieldId = $(this).attr('id').replace('gt-filter-', '');
                    const value = $(this).val();
                    
                    self.log('filtering', `Filter changed: Field ${fieldId}`, {
                        fieldId: fieldId,
                        value: value,
                        element: this
                    });
                }
            });
            
            // Monitor sort clicks
            $(document).on('click', '.gt-sortable', function() {
                if (self.isEnabled('sorting')) {
                    const fieldId = $(this).data('field-id');
                    const currentOrder = $(this).data('sort-order') || 'none';
                    
                    self.log('sorting', `Sort clicked: Field ${fieldId}`, {
                        fieldId: fieldId,
                        currentOrder: currentOrder,
                        element: this
                    });
                }
            });
            
            // Monitor cell editing
            $(document).on('click', '.gt-editable-cell', function() {
                if (self.isEnabled('frontend')) {
                    const fieldId = $(this).data('field-id');
                    const entryId = $(this).data('entry-id');
                    
                    self.log('frontend', `Cell edit started: Entry ${entryId}, Field ${fieldId}`, {
                        entryId: entryId,
                        fieldId: fieldId,
                        currentValue: $(this).text(),
                        element: this
                    });
                }
            });
        },
        
        /**
         * Send debug data to backend
         */
        sendToBackend: function(category, message, data, level) {
            // Only send errors and important events to backend
            if (level === 'error' || category === 'ajax') {
                $.post(gtDebug.ajaxurl, {
                    action: 'gt_debug_frontend',
                    category: category,
                    message: message,
                    data: data,
                    level: level,
                    nonce: gtDebug.nonce,
                    url: window.location.href,
                    userAgent: navigator.userAgent
                });
            }
        },
        
        /**
         * Attach debug utilities to window for manual debugging
         */
        attachToWindow: function() {
            window.gtDebug = {
                log: this.log.bind(this),
                error: this.error.bind(this),
                warn: this.warn.bind(this),
                info: this.info.bind(this),
                startTimer: this.startTimer.bind(this),
                endTimer: this.endTimer.bind(this),
                isEnabled: this.isEnabled.bind(this),
                config: this.config,
                timers: this.timers,
                
                // Quick access methods
                logAjax: this.logAjax.bind(this),
                logFilter: function(fieldId, value) {
                    this.log('filtering', `Manual filter log: Field ${fieldId}`, { fieldId, value });
                }.bind(this),
                logSort: function(fieldId, order) {
                    this.log('sorting', `Manual sort log: Field ${fieldId}`, { fieldId, order });
                }.bind(this),
                
                // #1563: dumpTable is NOT .bind(this)-attached. `this` at
                // call time must refer to window.gtDebug (the object the
                // function lives on) so the calls to this.getCurrentFilters
                // and this.getCurrentSort below resolve to the siblings
                // declared further down. .bind(this) used to point them at
                // the outer GTDebugFrontend, where those methods don't
                // exist -- TypeError on every call.
                dumpTable: function() {
                    const tableData = {
                        config: window.gravityTablesConfig || 'Not found',
                        filters: this.getCurrentFilters(),
                        sort: this.getCurrentSort(),
                        entries: $('.gt-table tbody tr').length
                    };
                    this.log('frontend', 'Table dump', tableData);
                    return tableData;
                },
                
                getCurrentFilters: function() {
                    const filters = {};
                    $('.gt-filter-input').each(function() {
                        const fieldId = $(this).attr('id').replace('gt-filter-', '');
                        const value = $(this).val();
                        if (value) {
                            filters[fieldId] = value;
                        }
                    });
                    return filters;
                },
                
                getCurrentSort: function() {
                    const sortElement = $('.gt-sortable[data-sort-order]').first();
                    return {
                        fieldId: sortElement.data('field-id'),
                        order: sortElement.data('sort-order')
                    };
                }
            };
            
            this.log('frontend', 'Debug utilities attached to window.gtDebug');
        },
        
        /**
         * Log conditional formatting application
         */
        logConditionalFormatting: function(fieldId, rules, appliedCount) {
            if (!this.isEnabled('conditional')) {
                return;
            }
            
            this.log('conditional', `Conditional formatting applied: Field ${fieldId}`, {
                fieldId: fieldId,
                rulesCount: rules.length,
                appliedCount: appliedCount,
                rules: rules
            });
        },
        
        /**
         * Log lookup field processing
         */
        logLookup: function(fieldId, config, options) {
            if (!this.isEnabled('lookup')) {
                return;
            }
            
            this.log('lookup', `Lookup field processed: Field ${fieldId}`, {
                fieldId: fieldId,
                config: config,
                optionsCount: options ? options.length : 0,
                options: options
            });
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        GTDebugFrontend.init();
    });
    
})(jQuery);