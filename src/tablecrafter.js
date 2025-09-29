/**
 * TableCrafter - A lightweight, mobile-responsive data table library
 * @version 1.0.0
 * @author Fahad Murtaza
 * @license MIT
 */

class TableCrafter {
  constructor(container, config = {}) {
    // Handle container parameter
    this.container = this.resolveContainer(container);
    if (!this.container) {
      throw new Error('Container element not found');
    }

    // Set up default configuration
    this.config = {
      data: [],
      columns: [],
      editable: false,
      responsive: true,
      mobileBreakpoint: 768,
      pageSize: 25,
      pagination: false,
      sortable: true,
      filterable: true,
      exportable: false,
      exportFiltered: true,
      exportFilename: 'table-export.csv',
      currentPage: 1,
      ...config
    };

    // Internal state
    this.data = [];
    this.currentPage = this.config.currentPage || 1;
    this.sortField = null;
    this.sortOrder = 'asc';
    this.filters = {};
    this.isLoading = false;
    this.editingCell = null;

    // Initialize if data provided
    if (this.config.data) {
      if (Array.isArray(this.config.data)) {
        this.data = [...this.config.data];
      } else if (typeof this.config.data === 'string') {
        // URL provided, will load asynchronously
        this.dataUrl = this.config.data;
      }
    }

    // Bind resize handler if responsive
    if (this.config.responsive) {
      this.handleResize = this.handleResize.bind(this);
      window.addEventListener('resize', this.handleResize);
    }
  }

  /**
   * Resolve container from selector or element
   */
  resolveContainer(container) {
    if (typeof container === 'string') {
      return document.querySelector(container);
    } else if (container instanceof HTMLElement) {
      return container;
    }
    return null;
  }

  /**
   * Load data from URL
   */
  async loadData() {
    if (!this.dataUrl) {
      return Promise.resolve(this.data);
    }

    this.isLoading = true;
    
    try {
      const response = await fetch(this.dataUrl);
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json();
      this.data = data;
      this.isLoading = false;
      
      if (this.container.querySelector('.tc-wrapper')) {
        this.render();
      }
      
      return data;
    } catch (error) {
      this.isLoading = false;
      throw error;
    }
  }

  /**
   * Get current data
   */
  getData() {
    return this.data;
  }

  /**
   * Set data
   */
  setData(data) {
    this.data = data;
    if (this.container.querySelector('.tc-wrapper')) {
      this.render();
    }
  }

  /**
   * Check if mobile viewport
   */
  isMobile() {
    return window.innerWidth <= this.config.mobileBreakpoint;
  }

  /**
   * Main render method
   */
  render() {
    // Clear container
    this.container.innerHTML = '';

    // Create wrapper
    const wrapper = document.createElement('div');
    wrapper.className = 'tc-wrapper';

    // Add filters if enabled
    if (this.config.filterable) {
      wrapper.appendChild(this.renderFilters());
    }

    // Add export controls if enabled
    if (this.config.exportable) {
      wrapper.appendChild(this.renderExportControls());
    }

    // Render based on viewport
    if (this.config.responsive && this.isMobile()) {
      wrapper.appendChild(this.renderCards());
    } else {
      wrapper.appendChild(this.renderTable());
    }

    // Add pagination if enabled and needed
    if (this.config.pagination && this.shouldShowPagination()) {
      wrapper.appendChild(this.renderPagination());
    }

    this.container.appendChild(wrapper);
  }

  /**
   * Render table view
   */
  renderTable() {
    const tableContainer = document.createElement('div');
    tableContainer.className = 'tc-table-container';

    const table = document.createElement('table');
    table.className = 'tc-table';

    // Render header
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');

    this.config.columns.forEach(column => {
      const th = document.createElement('th');
      th.textContent = column.label;
      th.dataset.field = column.field;

      if (this.config.sortable && column.sortable !== false) {
        th.className = 'tc-sortable';
        th.addEventListener('click', () => this.sort(column.field));
      }

      headerRow.appendChild(th);
    });

    thead.appendChild(headerRow);
    table.appendChild(thead);

    // Render body
    const tbody = document.createElement('tbody');
    
    const displayData = this.getPaginatedData();
    
    if (displayData.length === 0) {
      // Show no results message
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = this.config.columns.length;
      td.className = 'tc-no-results';
      td.textContent = 'No results found';
      td.style.textAlign = 'center';
      td.style.padding = '20px';
      tr.appendChild(td);
      tbody.appendChild(tr);
    } else {
      displayData.forEach((row, rowIndex) => {
        const actualRowIndex = this.config.pagination ? 
          (this.currentPage - 1) * this.config.pageSize + rowIndex : 
          rowIndex;
        const tr = document.createElement('tr');
        tr.dataset.rowIndex = actualRowIndex;

        this.config.columns.forEach(column => {
          const td = document.createElement('td');
          td.textContent = row[column.field] || '';
          td.dataset.field = column.field;

          // Make cell editable if configured
          if (this.config.editable && column.editable) {
            td.className = 'tc-editable';
            td.addEventListener('click', (e) => this.startEdit(e, actualRowIndex, column.field));
          }

          tr.appendChild(td);
        });

        tbody.appendChild(tr);
      });
    }

    table.appendChild(tbody);
    tableContainer.appendChild(table);

    return tableContainer;
  }

  /**
   * Render cards view for mobile
   */
  renderCards() {
    const cardsContainer = document.createElement('div');
    cardsContainer.className = 'tc-cards-container';

    const displayData = this.getPaginatedData();
    
    if (displayData.length === 0) {
      // Show no results message
      const noResults = document.createElement('div');
      noResults.className = 'tc-no-results';
      noResults.textContent = 'No results found';
      noResults.style.textAlign = 'center';
      noResults.style.padding = '20px';
      cardsContainer.appendChild(noResults);
    } else {
      displayData.forEach((row, rowIndex) => {
        const actualRowIndex = this.config.pagination ? 
          (this.currentPage - 1) * this.config.pageSize + rowIndex : 
          rowIndex;
        const card = document.createElement('div');
        card.className = 'tc-card';
        card.dataset.rowIndex = actualRowIndex;

        // Card header
        const cardHeader = document.createElement('div');
        cardHeader.className = 'tc-card-header';
        
        // Use first column as title
        const firstColumn = this.config.columns[0];
        if (firstColumn) {
          cardHeader.textContent = row[firstColumn.field] || `Item ${actualRowIndex + 1}`;
        }
        
        card.appendChild(cardHeader);

        // Card body with fields
        const cardBody = document.createElement('div');
        cardBody.className = 'tc-card-body';

        this.config.columns.forEach(column => {
          const field = document.createElement('div');
          field.className = 'tc-card-field';

          const label = document.createElement('span');
          label.className = 'tc-card-label';
          label.textContent = column.label + ':';

          const value = document.createElement('span');
          value.className = 'tc-card-value';
          value.textContent = row[column.field] || '';
          value.dataset.field = column.field;

          // Make field editable if configured
          if (this.config.editable && column.editable) {
            value.className += ' tc-editable';
            value.addEventListener('click', (e) => this.startEdit(e, actualRowIndex, column.field));
          }

          field.appendChild(label);
          field.appendChild(value);
          cardBody.appendChild(field);
        });

        card.appendChild(cardBody);
        cardsContainer.appendChild(card);
      });
    }

    return cardsContainer;
  }

  /**
   * Start editing a cell
   */
  startEdit(event, rowIndex, field) {
    const target = event.currentTarget;
    
    // Don't start edit if already editing
    if (this.editingCell === target) {
      return;
    }

    // Cancel any existing edit
    if (this.editingCell) {
      this.cancelEdit();
    }

    const currentValue = this.data[rowIndex][field];
    
    // Create input
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue || '';
    input.className = 'tc-edit-input';

    // Store original value
    input.dataset.originalValue = currentValue || '';
    input.dataset.rowIndex = rowIndex;
    input.dataset.field = field;

    // Replace content with input
    target.innerHTML = '';
    target.appendChild(input);
    
    // Focus and select
    input.focus();
    input.select();

    // Set current editing cell
    this.editingCell = target;

    // Handle blur
    input.addEventListener('blur', () => this.saveEdit(input));

    // Handle Enter/Escape keys
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        this.saveEdit(input);
      } else if (e.key === 'Escape') {
        this.cancelEdit();
      }
    });
  }

  /**
   * Save edited value
   */
  saveEdit(input) {
    const rowIndex = parseInt(input.dataset.rowIndex);
    const field = input.dataset.field;
    const oldValue = input.dataset.originalValue;
    const newValue = input.value;

    // Update data
    this.data[rowIndex][field] = newValue;

    // Call onEdit callback if provided
    if (this.config.onEdit) {
      this.config.onEdit({
        row: rowIndex,
        field: field,
        oldValue: oldValue,
        newValue: newValue
      });
    }

    // Update display
    const parent = input.parentElement;
    parent.textContent = newValue;
    
    // Clear editing state
    this.editingCell = null;
  }

  /**
   * Cancel editing
   */
  cancelEdit() {
    if (!this.editingCell) return;

    const input = this.editingCell.querySelector('input');
    if (input) {
      this.editingCell.textContent = input.dataset.originalValue;
    }
    
    this.editingCell = null;
  }

  /**
   * Get filtered data
   */
  getFilteredData() {
    if (!this.config.filterable || Object.keys(this.filters).length === 0) {
      return this.data;
    }

    return this.data.filter(row => {
      return Object.entries(this.filters).every(([field, filterValue]) => {
        if (!filterValue) return true;
        const cellValue = (row[field] || '').toString().toLowerCase();
        return cellValue.includes(filterValue.toLowerCase());
      });
    });
  }

  /**
   * Get paginated data for current page
   */
  getPaginatedData() {
    const filteredData = this.getFilteredData();
    
    if (!this.config.pagination) {
      return filteredData;
    }

    const startIndex = (this.currentPage - 1) * this.config.pageSize;
    const endIndex = startIndex + this.config.pageSize;
    return filteredData.slice(startIndex, endIndex);
  }

  /**
   * Get total number of pages
   */
  getTotalPages() {
    if (!this.config.pagination) {
      return 1;
    }
    const filteredData = this.getFilteredData();
    return Math.ceil(filteredData.length / this.config.pageSize);
  }

  /**
   * Check if pagination should be shown
   */
  shouldShowPagination() {
    const filteredData = this.getFilteredData();
    return filteredData.length > this.config.pageSize;
  }

  /**
   * Go to specific page
   */
  goToPage(page) {
    const totalPages = this.getTotalPages();
    if (page >= 1 && page <= totalPages) {
      this.currentPage = page;
      this.render();
    }
  }

  /**
   * Go to next page
   */
  nextPage() {
    this.goToPage(this.currentPage + 1);
  }

  /**
   * Go to previous page
   */
  prevPage() {
    this.goToPage(this.currentPage - 1);
  }

  /**
   * Render pagination controls
   */
  renderPagination() {
    const pagination = document.createElement('div');
    pagination.className = 'tc-pagination';

    const totalPages = this.getTotalPages();
    const filteredData = this.getFilteredData();
    const startIndex = (this.currentPage - 1) * this.config.pageSize + 1;
    const endIndex = Math.min(this.currentPage * this.config.pageSize, filteredData.length);

    // Pagination info
    const paginationInfo = document.createElement('div');
    paginationInfo.className = 'tc-pagination-info';
    paginationInfo.textContent = `${startIndex}-${endIndex} of ${filteredData.length}`;

    // Pagination controls
    const paginationControls = document.createElement('div');
    paginationControls.className = 'tc-pagination-controls';

    // Previous button
    const prevBtn = document.createElement('button');
    prevBtn.className = 'tc-prev-btn';
    prevBtn.textContent = 'Previous';
    prevBtn.disabled = this.currentPage === 1;
    prevBtn.addEventListener('click', () => this.prevPage());

    // Current page info
    const currentPage = document.createElement('span');
    currentPage.className = 'tc-current-page';
    currentPage.textContent = this.currentPage.toString();

    const separator = document.createElement('span');
    separator.textContent = ' of ';

    const totalPagesSpan = document.createElement('span');
    totalPagesSpan.className = 'tc-total-pages';
    totalPagesSpan.textContent = totalPages.toString();

    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.className = 'tc-next-btn';
    nextBtn.textContent = 'Next';
    nextBtn.disabled = this.currentPage === totalPages;
    nextBtn.addEventListener('click', () => this.nextPage());

    // Assemble controls
    paginationControls.appendChild(prevBtn);
    paginationControls.appendChild(currentPage);
    paginationControls.appendChild(separator);
    paginationControls.appendChild(totalPagesSpan);
    paginationControls.appendChild(nextBtn);

    // Assemble pagination
    pagination.appendChild(paginationInfo);
    pagination.appendChild(paginationControls);

    return pagination;
  }

  /**
   * Render filter controls
   */
  renderFilters() {
    const filtersContainer = document.createElement('div');
    filtersContainer.className = 'tc-filters';

    this.config.columns.forEach(column => {
      const filterDiv = document.createElement('div');
      filterDiv.className = 'tc-filter';

      const label = document.createElement('label');
      label.textContent = column.label;
      label.className = 'tc-filter-label';

      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'tc-filter-input';
      input.placeholder = `Filter ${column.label}...`;
      input.dataset.field = column.field;
      input.value = this.filters[column.field] || '';

      input.addEventListener('input', (e) => {
        this.setFilter(column.field, e.target.value);
      });

      filterDiv.appendChild(label);
      filterDiv.appendChild(input);
      filtersContainer.appendChild(filterDiv);
    });

    return filtersContainer;
  }

  /**
   * Set filter for a field
   */
  setFilter(field, value) {
    if (!value || value.trim() === '') {
      delete this.filters[field];
    } else {
      this.filters[field] = value.trim();
    }

    // Reset to first page when filtering
    this.currentPage = 1;

    // Call onFilter callback if provided
    if (this.config.onFilter) {
      const filteredData = this.getFilteredData();
      this.config.onFilter({
        filters: { ...this.filters },
        filteredData: filteredData
      });
    }

    this.render();
  }

  /**
   * Clear all filters
   */
  clearFilters() {
    this.filters = {};
    this.currentPage = 1;
    this.render();
  }

  /**
   * Render export controls
   */
  renderExportControls() {
    const exportContainer = document.createElement('div');
    exportContainer.className = 'tc-export-controls';

    const exportCsvBtn = document.createElement('button');
    exportCsvBtn.className = 'tc-export-csv';
    exportCsvBtn.textContent = 'Export CSV';
    exportCsvBtn.addEventListener('click', () => this.downloadCSV());

    exportContainer.appendChild(exportCsvBtn);
    return exportContainer;
  }

  /**
   * Get exportable data (respects filtering if enabled)
   */
  getExportableData() {
    if (this.config.exportFiltered) {
      return this.getFilteredData();
    }
    return this.data;
  }

  /**
   * Get exportable columns (excludes non-exportable columns)
   */
  getExportableColumns() {
    return this.config.columns.filter(column => column.exportable !== false);
  }

  /**
   * Escape CSV field value
   */
  escapeCSVField(value) {
    if (value === null || value === undefined) {
      return '""';
    }
    
    const stringValue = value.toString();
    
    // If the value contains comma, newline, or quote, wrap in quotes and escape quotes
    if (stringValue.includes(',') || stringValue.includes('\n') || stringValue.includes('"')) {
      return '"' + stringValue.replace(/"/g, '""') + '"';
    }
    
    // For simple values without special characters, don't quote numbers
    if (!isNaN(stringValue) && !isNaN(parseFloat(stringValue))) {
      return stringValue;
    }
    
    // Quote text values
    return '"' + stringValue + '"';
  }

  /**
   * Export data to CSV format
   */
  exportToCSV() {
    const exportableColumns = this.getExportableColumns();
    const exportableData = this.getExportableData();

    // Create header row
    const headerRow = exportableColumns.map(column => column.label).join(',');

    // Create data rows
    const dataRows = exportableData.map(row => {
      return exportableColumns.map(column => {
        const value = row[column.field];
        return this.escapeCSVField(value);
      }).join(',');
    });

    const csvContent = [headerRow, ...dataRows].join('\n');

    // Call onExport callback if provided
    if (this.config.onExport) {
      this.config.onExport({
        format: 'csv',
        data: exportableData,
        csvData: csvContent
      });
    }

    return csvContent;
  }

  /**
   * Download CSV file
   */
  downloadCSV() {
    const csvContent = this.exportToCSV();
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = this.config.exportFilename;
    link.click();

    // Clean up
    URL.revokeObjectURL(url);
  }

  /**
   * Sort data
   */
  sort(field) {
    if (this.sortField === field) {
      this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortField = field;
      this.sortOrder = 'asc';
    }

    this.data.sort((a, b) => {
      const aVal = a[field];
      const bVal = b[field];
      
      if (aVal === bVal) return 0;
      
      const result = aVal < bVal ? -1 : 1;
      return this.sortOrder === 'asc' ? result : -result;
    });

    // Reset to first page after sorting
    this.currentPage = 1;
    this.render();
  }

  /**
   * Handle window resize
   */
  handleResize() {
    // Re-render if crossing mobile breakpoint
    const isMobileNow = this.isMobile();
    const wrapper = this.container.querySelector('.tc-wrapper');
    
    if (!wrapper) return;

    const hasCards = wrapper.querySelector('.tc-cards-container');
    const hasTable = wrapper.querySelector('.tc-table-container');

    if ((isMobileNow && hasTable) || (!isMobileNow && hasCards)) {
      this.render();
    }
  }

  /**
   * Destroy the table instance
   */
  destroy() {
    // Remove event listeners
    if (this.config.responsive) {
      window.removeEventListener('resize', this.handleResize);
    }

    // Clear container
    this.container.innerHTML = '';
    
    // Clear data
    this.data = [];
    this.editingCell = null;
  }
}

// Export for different module systems
if (typeof module !== 'undefined' && module.exports) {
  module.exports = TableCrafter;
}

if (typeof define === 'function' && define.amd) {
  define([], function() {
    return TableCrafter;
  });
}

if (typeof window !== 'undefined') {
  window.TableCrafter = TableCrafter;
}