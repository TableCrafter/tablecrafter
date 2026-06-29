jQuery(document).ready(function($) {
    
    class GenericGravityFormsTable {
        constructor() {
            this.container = $('#ajs-load-tracker-container');
            this.formId = this.container.data('form-id') || 1;
            this.showSelection = this.container.data('show-selection') === true;
            this.bulkActions = this.container.data('bulk-actions') || '';
            this.currentPage = 1;
            this.perPage = 25;
            this.sortField = 'date_created';
            this.sortOrder = 'desc';
            this.searchTerm = '';
            this.userFilter = '';
            this.dateFrom = '';
            this.dateTo = '';
            this.isLoading = false;
            this.columns = [];
            this.selectedEntries = new Set();
            this.advancedFilters = [];
            
            this.init();
        }
        
        init() {
            this.extractColumns();
            this.bindEvents();
            this.loadEntries();
        }
        
        extractColumns() {
            // Extract column configuration from table headers
            $('#ajs-load-tracker-table th[data-field]').each((index, header) => {
                const field = $(header).data('field');
                if (field !== 'actions') {
                    this.columns.push(field);
                }
            });
        }
        
        bindEvents() {
            // Search controls
            $('#ajs-search-btn').on('click', () => this.performSearch());
            $('#ajs-clear-btn').on('click', () => this.clearSearch());
            $('#ajs-search-input').on('keypress', (e) => {
                if (e.which === 13) this.performSearch();
            });
            
            // Advanced filters
            $('#ajs-toggle-filters').on('click', () => this.toggleAdvancedFilters());
            $('#ajs-add-filter').on('click', () => this.addFilterCondition());
            $('#ajs-apply-filters').on('click', () => this.applyAdvancedFilters());
            $('#ajs-clear-filters').on('click', () => this.clearAdvancedFilters());
            $(document).on('click', '.ajs-remove-filter', (e) => {
                this.removeFilterCondition($(e.target).closest('.ajs-filter-condition'));
            });
            
            // Selection controls
            if (this.showSelection) {
                $('#ajs-select-all').on('change', (e) => this.handleSelectAll(e.target.checked));
                $(document).on('change', '.ajs-entry-checkbox', (e) => {
                    this.handleEntrySelection($(e.target));
                });
            }
            
            // Bulk actions
            $('.ajs-bulk-btn').on('click', (e) => {
                const action = $(e.target).data('action');
                this.handleBulkAction(action);
            });
            $('.ajs-clear-selection').on('click', () => this.clearSelection());
            
            // Sorting
            $('#ajs-load-tracker-table th[data-sortable="true"]').on('click', (e) => {
                this.handleSort($(e.currentTarget));
            });
            
            // Pagination
            $('#ajs-prev-page').on('click', () => this.previousPage());
            $('#ajs-next-page').on('click', () => this.nextPage());
            $(document).on('click', '.ajs-page-number', (e) => {
                this.goToPage(parseInt($(e.target).data('page')));
            });
            
            // Row editing
            $(document).on('click', '.ajs-edit-btn', (e) => {
                this.editRow($(e.target).closest('tr'));
            });
            $(document).on('click', '.ajs-save-btn', (e) => {
                this.saveRow($(e.target).closest('tr'));
            });
            $(document).on('click', '.ajs-cancel-btn', (e) => {
                this.cancelEdit($(e.target).closest('tr'));
            });
        }
        
        performSearch() {
            this.searchTerm = $('#ajs-search-input').val();
            this.userFilter = $('#ajs-user-filter').val();
            this.dateFrom = $('#ajs-date-from').val();
            this.dateTo = $('#ajs-date-to').val();
            this.currentPage = 1;
            this.loadEntries();
        }
        
        clearSearch() {
            $('#ajs-search-input').val('');
            $('#ajs-user-filter').val('');
            $('#ajs-date-from').val('');
            $('#ajs-date-to').val('');
            this.searchTerm = '';
            this.userFilter = '';
            this.dateFrom = '';
            this.dateTo = '';
            this.currentPage = 1;
            this.loadEntries();
        }
        
        handleSort(header) {
            const field = header.data('field');
            
            if (this.sortField === field) {
                this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortField = field;
                this.sortOrder = 'asc';
            }
            
            this.updateSortIndicators(header);
            this.loadEntries();
        }
        
        updateSortIndicators(activeHeader) {
            // Remove all sort indicators
            $('#ajs-load-tracker-table th').removeClass('sort-asc sort-desc');
            
            // Add indicator to active header
            activeHeader.addClass(this.sortOrder === 'asc' ? 'sort-asc' : 'sort-desc');
        }
        
        previousPage() {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.loadEntries();
            }
        }
        
        nextPage() {
            this.currentPage++;
            this.loadEntries();
        }
        
        goToPage(page) {
            this.currentPage = page;
            this.loadEntries();
        }
        
        loadEntries() {
            if (this.isLoading) return;
            
            this.isLoading = true;
            this.showLoading();
            
            const data = {
                action: 'ajs_get_entries',
                nonce: ajs_load_tracker.nonce,
                form_id: this.formId,
                page: this.currentPage,
                per_page: this.perPage,
                search: this.searchTerm,
                user_filter: this.userFilter,
                date_from: this.dateFrom,
                date_to: this.dateTo,
                sort_field: this.sortField,
                sort_order: this.sortOrder,
                columns: this.columns
            };
            
            $.post(ajs_load_tracker.ajax_url, data)
                .done((response) => {
                    if (response.success) {
                        this.renderEntries(response.data);
                        this.updatePagination(response.data);
                    } else {
                        this.showError('Failed to load entries');
                    }
                })
                .fail(() => {
                    this.showError('Network error occurred');
                })
                .always(() => {
                    this.isLoading = false;
                });
        }
        
        renderEntries(data) {
            const tbody = $('#ajs-table-body');
            tbody.empty();
            
            if (data.entries.length === 0) {
                const colspan = this.columns.length + 1 + (this.showSelection ? 1 : 0); // +1 for actions, +1 for selection if enabled
                tbody.html(`<tr><td colspan="${colspan}" class="no-entries">No entries found</td></tr>`);
                return;
            }
            
            data.entries.forEach(entry => {
                const row = this.createEntryRow(entry);
                tbody.append(row);
            });
        }
        
        createEntryRow(entry) {
            let rowHtml = `<tr data-entry-id="${entry.entry_id}">`;
            
            // Add selection checkbox if enabled
            if (this.showSelection) {
                const isSelected = this.selectedEntries.has(entry.entry_id);
                rowHtml += `
                    <td class="ajs-select-cell">
                        <input type="checkbox" class="ajs-entry-checkbox" 
                               value="${entry.entry_id}" ${isSelected ? 'checked' : ''} />
                    </td>`;
            }
            
            // Generate cells dynamically based on columns
            this.columns.forEach(field => {
                const value = entry[field] || '';
                const cellClass = `${field}-cell`;
                let cellContent = value;
                
                // Special handling for certain field types
                if (field === 'date_created') {
                    cellContent = this.formatDate(value);
                    rowHtml += `<td class="${cellClass}" data-sort-value="${this.getDateSortValue(cellContent)}">${cellContent}</td>`;
                } else if (this.isNumericField(field)) {
                    rowHtml += `<td class="${cellClass}" data-sort-value="${value || 0}">${value}</td>`;
                } else {
                    rowHtml += `<td class="${cellClass}">${value}</td>`;
                }
            });
            
            // Add actions column
            rowHtml += `
                <td class="actions-cell">
                    <button class="ajs-edit-btn" type="button">Edit</button>
                </td>
            </tr>`;
            
            return rowHtml;
        }
        
        isNumericField(field) {
            // Helper to identify numeric fields that should be sorted numerically
            return field.includes('quantity') || field.includes('amount') || field.includes('number');
        }
        
        formatDate(dateStr) {
            // Convert date to consistent format
            if (!dateStr) return '';
            
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return dateStr;
            
            return date.toLocaleDateString('en-US');
        }
        
        getDateSortValue(dateStr) {
            if (!dateStr || dateStr === '') return 0;
            
            // Convert MM/DD/YYYY to sortable timestamp
            const parts = dateStr.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
            if (parts) {
                const date = new Date(parts[3], parts[1] - 1, parts[2]);
                return date.getTime();
            }
            return 0;
        }
        
        updatePagination(data) {
            const { total, page, per_page, total_pages } = data;
            const start = ((page - 1) * per_page) + 1;
            const end = Math.min(page * per_page, total);
            
            // Update info text
            $('#ajs-showing-info').text(`Showing ${start} - ${end} of ${total} entries`);
            
            // Update pagination controls
            $('#ajs-prev-page').prop('disabled', page <= 1);
            $('#ajs-next-page').prop('disabled', page >= total_pages);
            
            // Generate page numbers
            this.generatePageNumbers(page, total_pages);
        }
        
        generatePageNumbers(currentPage, totalPages) {
            const pageNumbers = $('#ajs-page-numbers');
            pageNumbers.empty();
            
            const maxVisible = 5;
            let start = Math.max(1, currentPage - Math.floor(maxVisible / 2));
            let end = Math.min(totalPages, start + maxVisible - 1);
            
            if (end - start + 1 < maxVisible) {
                start = Math.max(1, end - maxVisible + 1);
            }
            
            if (start > 1) {
                pageNumbers.append('<span class="ajs-page-number" data-page="1">1</span>');
                if (start > 2) {
                    pageNumbers.append('<span class="ajs-page-ellipsis">...</span>');
                }
            }
            
            for (let i = start; i <= end; i++) {
                const className = i === currentPage ? 'ajs-page-number current' : 'ajs-page-number';
                pageNumbers.append(`<span class="${className}" data-page="${i}">${i}</span>`);
            }
            
            if (end < totalPages) {
                if (end < totalPages - 1) {
                    pageNumbers.append('<span class="ajs-page-ellipsis">...</span>');
                }
                pageNumbers.append(`<span class="ajs-page-number" data-page="${totalPages}">${totalPages}</span>`);
            }
        }
        
        editRow(row) {
            const entryId = row.data('entry-id');
            
            // Convert editable cells to inputs
            this.columns.forEach(field => {
                if (field === 'date_created') return; // Skip non-editable fields
                
                const cell = row.find(`.${field}-cell`);
                if (cell.length) {
                    const value = cell.text();
                    const input = $(`<input type="text" class="edit-input" data-field="${field}" value="${value}" />`);
                    cell.html(input);
                }
            });
            
            // Update actions
            row.find('.actions-cell').html(`
                <button class="ajs-save-btn" type="button">Save</button>
                <button class="ajs-cancel-btn" type="button">Cancel</button>
            `);
            
            row.addClass('editing');
        }
        
        saveRow(row) {
            const entryId = row.data('entry-id');
            const updates = {};
            
            // Collect updated values from input fields
            row.find('.edit-input').each(function() {
                const input = $(this);
                const field = input.data('field');
                const value = input.val();
                
                if (field) {
                    updates[field] = value;
                }
            });
            
            // Show saving indicator
            row.find('.actions-cell').html('<span class="saving">Saving...</span>');
            
            const data = {
                action: 'ajs_update_entry',
                nonce: ajs_load_tracker.nonce,
                entry_id: entryId,
                updates: updates
            };
            
            $.post(ajs_load_tracker.ajax_url, data)
                .done((response) => {
                    if (response.success) {
                        // Update the row with new values and exit edit mode
                        this.updateRowValues(row, updates);
                        row.removeClass('editing');
                        row.find('.actions-cell').html('<button class="ajs-edit-btn" type="button">Edit</button>');
                        
                        // Show success message briefly
                        this.showMessage('Entry updated successfully', 'success');
                    } else {
                        this.showMessage(response.data || 'Failed to save changes', 'error');
                        row.find('.actions-cell').html(`
                            <button class="ajs-save-btn" type="button">Save</button>
                            <button class="ajs-cancel-btn" type="button">Cancel</button>
                        `);
                    }
                })
                .fail(() => {
                    this.showMessage('Network error occurred', 'error');
                    row.find('.actions-cell').html(`
                        <button class="ajs-save-btn" type="button">Save</button>
                        <button class="ajs-cancel-btn" type="button">Cancel</button>
                    `);
                });
        }
        
        updateRowValues(row, updates) {
            // Update cell contents with new values
            Object.keys(updates).forEach(field => {
                const cell = row.find(`.${field}-cell`);
                if (cell.length) {
                    const value = updates[field];
                    cell.text(value);
                    
                    // Update sort value for numeric fields
                    if (this.isNumericField(field)) {
                        cell.attr('data-sort-value', value || 0);
                    }
                }
            });
        }
        
        showMessage(message, type) {
            // Create and show a temporary message
            const messageDiv = $(`<div class="ajs-message ajs-message-${type}">${message}</div>`);
            $('#ajs-load-tracker-container').prepend(messageDiv);
            
            setTimeout(() => {
                messageDiv.fadeOut(() => messageDiv.remove());
            }, 3000);
        }
        
        cancelEdit(row) {
            // Reload the current view to restore original values
            this.loadEntries();
        }
        
        // Selection methods
        handleSelectAll(checked) {
            $('.ajs-entry-checkbox').prop('checked', checked);
            
            if (checked) {
                $('.ajs-entry-checkbox').each((index, checkbox) => {
                    this.selectedEntries.add(parseInt($(checkbox).val()));
                });
            } else {
                this.selectedEntries.clear();
            }
            
            this.updateSelectionUI();
        }
        
        handleEntrySelection(checkbox) {
            const entryId = parseInt(checkbox.val());
            
            if (checkbox.is(':checked')) {
                this.selectedEntries.add(entryId);
            } else {
                this.selectedEntries.delete(entryId);
                $('#ajs-select-all').prop('checked', false);
            }
            
            // Check if all visible entries are selected
            const totalVisible = $('.ajs-entry-checkbox').length;
            const selectedVisible = $('.ajs-entry-checkbox:checked').length;
            
            if (selectedVisible === totalVisible && totalVisible > 0) {
                $('#ajs-select-all').prop('checked', true);
            }
            
            this.updateSelectionUI();
        }
        
        updateSelectionUI() {
            const selectedCount = this.selectedEntries.size;
            $('#ajs-selected-count').text(selectedCount);
            
            if (selectedCount > 0) {
                $('.ajs-bulk-actions-bar').show();
            } else {
                $('.ajs-bulk-actions-bar').hide();
            }
        }
        
        clearSelection() {
            this.selectedEntries.clear();
            $('.ajs-entry-checkbox, #ajs-select-all').prop('checked', false);
            this.updateSelectionUI();
        }
        
        // Bulk action methods
        handleBulkAction(action) {
            if (this.selectedEntries.size === 0) {
                this.showMessage('Please select entries first', 'error');
                return;
            }
            
            const entryIds = Array.from(this.selectedEntries);
            
            switch (action) {
                case 'delete':
                    this.bulkDelete(entryIds);
                    break;
                case 'export':
                    this.bulkExport(entryIds);
                    break;
                case 'edit':
                    this.showBulkEditModal(entryIds);
                    break;
            }
        }
        
        bulkDelete(entryIds) {
            if (!confirm(`Are you sure you want to delete ${entryIds.length} entries? This action cannot be undone.`)) {
                return;
            }
            
            const data = {
                action: 'ajs_bulk_action',
                nonce: ajs_load_tracker.nonce,
                bulk_action: 'delete',
                entry_ids: entryIds,
                form_id: this.formId
            };
            
            $.post(ajs_load_tracker.ajax_url, data)
                .done((response) => {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        this.clearSelection();
                        this.loadEntries();
                    } else {
                        this.showMessage(response.data || 'Failed to delete entries', 'error');
                    }
                })
                .fail(() => {
                    this.showMessage('Network error occurred', 'error');
                });
        }
        
        bulkExport(entryIds) {
            const data = {
                action: 'ajs_bulk_action',
                nonce: ajs_load_tracker.nonce,
                bulk_action: 'export',
                entry_ids: entryIds,
                form_id: this.formId
            };
            
            $.post(ajs_load_tracker.ajax_url, data)
                .done((response) => {
                    if (response.success) {
                        this.downloadCSV(response.data.csv_data, response.data.filename);
                        this.showMessage(response.data.message, 'success');
                    } else {
                        this.showMessage(response.data || 'Failed to export entries', 'error');
                    }
                })
                .fail(() => {
                    this.showMessage('Network error occurred', 'error');
                });
        }
        
        downloadCSV(csvData, filename) {
            // Convert array to CSV string
            const csvContent = csvData.map(row => 
                row.map(field => `"${field}"`).join(',')
            ).join('\n');
            
            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
        }
        
        // Advanced filter methods
        toggleAdvancedFilters() {
            $('.ajs-advanced-filters').slideToggle();
        }
        
        addFilterCondition() {
            const conditionHtml = `
                <div class="ajs-filter-condition">
                    <select class="ajs-filter-field">
                        <option value="">Select Field</option>
                        ${this.getFieldOptions()}
                    </select>
                    <select class="ajs-filter-operator">
                        <option value="equals">Equals</option>
                        <option value="contains">Contains</option>
                        <option value="starts_with">Starts With</option>
                        <option value="ends_with">Ends With</option>
                        <option value="not_equals">Not Equals</option>
                        <option value="greater_than">Greater Than</option>
                        <option value="less_than">Less Than</option>
                    </select>
                    <input type="text" class="ajs-filter-value" placeholder="Filter value..." />
                    <button class="ajs-remove-filter" type="button">Remove</button>
                </div>
            `;
            
            $('#ajs-filter-conditions').append(conditionHtml);
        }
        
        removeFilterCondition(condition) {
            condition.remove();
        }
        
        getFieldOptions() {
            let options = '';
            
            $('#ajs-load-tracker-table th[data-field]').each((index, header) => {
                const field = $(header).data('field');
                const label = $(header).text();
                
                if (field !== 'actions' && field !== 'select') {
                    options += `<option value="${field}">${label}</option>`;
                }
            });
            
            return options;
        }
        
        applyAdvancedFilters() {
            this.advancedFilters = [];
            
            $('.ajs-filter-condition').each((index, condition) => {
                const field = $(condition).find('.ajs-filter-field').val();
                const operator = $(condition).find('.ajs-filter-operator').val();
                const value = $(condition).find('.ajs-filter-value').val();
                
                if (field && operator && value) {
                    this.advancedFilters.push({ field, operator, value });
                }
            });
            
            this.currentPage = 1;
            this.loadEntries();
        }
        
        clearAdvancedFilters() {
            this.advancedFilters = [];
            $('#ajs-filter-conditions').empty();
            this.loadEntries();
        }
        
        showLoading() {
            const colspan = this.columns.length + 1 + (this.showSelection ? 1 : 0); // +1 for actions, +1 for selection if enabled
            $('#ajs-table-body').html(`<tr><td colspan="${colspan}" class="loading">Loading entries...</td></tr>`);
        }
        
        showError(message) {
            const colspan = this.columns.length + 1 + (this.showSelection ? 1 : 0); // +1 for actions, +1 for selection if enabled
            $('#ajs-table-body').html(`<tr><td colspan="${colspan}" class="error">${message}</td></tr>`);
        }
    }
    
    // Initialize the generic gravity forms table
    if ($('#ajs-load-tracker-container').length) {
        new GenericGravityFormsTable();
    }
    
});