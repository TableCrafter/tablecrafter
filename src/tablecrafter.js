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
      // Advanced filtering configuration
      filters: {
        advanced: false,
        autoDetect: true,
        types: {}, // Custom filter types per column
        showClearAll: true
      },
      // Bulk operations configuration
      bulk: {
        enabled: false,
        operations: ['delete', 'export'],
        showProgress: true
      },
      // Add new entries configuration
      addNew: {
        enabled: false,
        modal: true,
        fields: [],
        validation: {}
      },
      // Mobile responsive configuration
      responsive: {
        enabled: true,
        breakpoints: {
          mobile: { width: 480, layout: 'cards' },
          tablet: { width: 768, layout: 'compact' },
          desktop: { width: 1024, layout: 'table' }
        },
        fieldVisibility: {}
      },
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
    this.selectedRows = new Set();
    this.filterTypes = {};
    this.uniqueValues = {};

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
   * Get filtered data with advanced filtering support
   */
  getFilteredData() {
    if (!this.config.filterable || Object.keys(this.filters).length === 0) {
      return this.data;
    }

    return this.data.filter(row => {
      return Object.entries(this.filters).every(([field, filterValue]) => {
        if (!filterValue || (Array.isArray(filterValue) && filterValue.length === 0)) {
          return true;
        }

        const cellValue = row[field];
        const filterType = this.filterTypes[field] || 'text';

        switch (filterType) {
          case 'multiselect':
            return Array.isArray(filterValue) && filterValue.includes(cellValue);

          case 'daterange':
            if (!cellValue) return false;
            const cellDate = new Date(cellValue);
            const fromDate = filterValue.from ? new Date(filterValue.from) : null;
            const toDate = filterValue.to ? new Date(filterValue.to) : null;
            
            if (fromDate && cellDate < fromDate) return false;
            if (toDate && cellDate > toDate) return false;
            return true;

          case 'numberrange':
            if (!cellValue && cellValue !== 0) return false;
            const numValue = parseFloat(cellValue);
            if (isNaN(numValue)) return false;
            
            if (filterValue.min !== undefined && numValue < filterValue.min) return false;
            if (filterValue.max !== undefined && numValue > filterValue.max) return false;
            return true;

          default: // text
            const cellString = (cellValue || '').toString().toLowerCase();
            const filterString = filterValue.toString().toLowerCase();
            return cellString.includes(filterString);
        }
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
   * Analyze data to detect filter types
   */
  detectFilterTypes() {
    if (!this.config.filters.autoDetect || this.data.length === 0) {
      return;
    }

    this.config.columns.forEach(column => {
      const field = column.field;
      const values = this.data.map(row => row[field]).filter(val => val != null);
      
      if (values.length === 0) return;

      // Store unique values for dropdowns
      this.uniqueValues[field] = [...new Set(values)];

      // Auto-detect filter type if not specified
      if (!this.config.filters.types[field]) {
        const sampleValue = values[0];
        
        // Check if it's a date
        if (this.isDateField(values)) {
          this.filterTypes[field] = 'daterange';
        }
        // Check if it's numeric
        else if (this.isNumericField(values)) {
          this.filterTypes[field] = 'numberrange';
        }
        // Check if it should be a multiselect (limited unique values)
        else if (this.uniqueValues[field].length <= 20 && this.uniqueValues[field].length > 1) {
          this.filterTypes[field] = 'multiselect';
        }
        // Default to text
        else {
          this.filterTypes[field] = 'text';
        }
      } else {
        this.filterTypes[field] = this.config.filters.types[field].type || 'text';
      }
    });
  }

  /**
   * Check if field contains date values
   */
  isDateField(values) {
    const datePatterns = [
      /^\d{4}-\d{2}-\d{2}/, // YYYY-MM-DD
      /^\d{2}\/\d{2}\/\d{4}/, // MM/DD/YYYY
      /^\d{2}-\d{2}-\d{4}/ // MM-DD-YYYY
    ];
    
    return values.slice(0, 5).every(val => {
      const str = val.toString();
      return datePatterns.some(pattern => pattern.test(str)) || !isNaN(Date.parse(str));
    });
  }

  /**
   * Check if field contains numeric values
   */
  isNumericField(values) {
    return values.slice(0, 10).every(val => !isNaN(parseFloat(val)) && isFinite(val));
  }

  /**
   * Render filter controls
   */
  renderFilters() {
    // Detect filter types first
    this.detectFilterTypes();

    const filtersContainer = document.createElement('div');
    filtersContainer.className = 'tc-filters';

    // Add clear all button if enabled
    if (this.config.filters.showClearAll) {
      const clearAllBtn = document.createElement('button');
      clearAllBtn.className = 'tc-clear-filters';
      clearAllBtn.textContent = 'Clear All Filters';
      clearAllBtn.addEventListener('click', () => this.clearFilters());
      filtersContainer.appendChild(clearAllBtn);
    }

    // Create filter row container
    const filterRow = document.createElement('div');
    filterRow.className = 'tc-filters-row';

    this.config.columns.forEach(column => {
      const filterType = this.filterTypes[column.field] || 'text';
      const filterDiv = this.createFilterControl(column, filterType);
      filterRow.appendChild(filterDiv);
    });

    filtersContainer.appendChild(filterRow);
    return filtersContainer;
  }

  /**
   * Create individual filter control
   */
  createFilterControl(column, filterType) {
    const filterDiv = document.createElement('div');
    filterDiv.className = 'tc-filter';

    const label = document.createElement('label');
    label.textContent = column.label;
    label.className = 'tc-filter-label';
    filterDiv.appendChild(label);

    switch (filterType) {
      case 'multiselect':
        filterDiv.appendChild(this.createMultiselectFilter(column));
        break;
      case 'daterange':
        filterDiv.appendChild(this.createDateRangeFilter(column));
        break;
      case 'numberrange':
        filterDiv.appendChild(this.createNumberRangeFilter(column));
        break;
      default:
        filterDiv.appendChild(this.createTextFilter(column));
    }

    return filterDiv;
  }

  /**
   * Create text filter input
   */
  createTextFilter(column) {
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'tc-filter-input';
    input.placeholder = `Filter ${column.label}...`;
    input.dataset.field = column.field;
    input.value = this.filters[column.field] || '';

    input.addEventListener('input', (e) => {
      this.setFilter(column.field, e.target.value);
    });

    return input;
  }

  /**
   * Create multiselect filter dropdown
   */
  createMultiselectFilter(column) {
    const container = document.createElement('div');
    container.className = 'tc-multiselect-container';

    const button = document.createElement('button');
    button.className = 'tc-multiselect-button';
    button.textContent = 'Select values...';
    button.type = 'button';

    const dropdown = document.createElement('div');
    dropdown.className = 'tc-multiselect-dropdown';
    dropdown.style.display = 'none';

    const uniqueValues = this.uniqueValues[column.field] || [];
    const currentFilter = this.filters[column.field] || [];

    uniqueValues.forEach(value => {
      const option = document.createElement('label');
      option.className = 'tc-multiselect-option';

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.value = value;
      checkbox.checked = currentFilter.includes(value);
      checkbox.addEventListener('change', () => {
        this.updateMultiselectFilter(column.field, dropdown);
      });

      option.appendChild(checkbox);
      option.appendChild(document.createTextNode(value));
      dropdown.appendChild(option);
    });

    button.addEventListener('click', () => {
      dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    });

    // Update button text based on selection
    this.updateMultiselectButton(button, currentFilter);

    container.appendChild(button);
    container.appendChild(dropdown);
    return container;
  }

  /**
   * Update multiselect filter based on checkbox changes
   */
  updateMultiselectFilter(field, dropdown) {
    const checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
    const selectedValues = Array.from(checkboxes)
      .filter(cb => cb.checked)
      .map(cb => cb.value);

    this.setFilter(field, selectedValues);
    
    // Update button text
    const button = dropdown.previousElementSibling;
    this.updateMultiselectButton(button, selectedValues);
  }

  /**
   * Update multiselect button text
   */
  updateMultiselectButton(button, selectedValues) {
    if (selectedValues.length === 0) {
      button.textContent = 'Select values...';
    } else if (selectedValues.length === 1) {
      button.textContent = selectedValues[0];
    } else {
      button.textContent = `${selectedValues.length} selected`;
    }
  }

  /**
   * Create date range filter
   */
  createDateRangeFilter(column) {
    const container = document.createElement('div');
    container.className = 'tc-daterange-container';

    const fromInput = document.createElement('input');
    fromInput.type = 'date';
    fromInput.className = 'tc-date-from';
    fromInput.placeholder = 'From';

    const toInput = document.createElement('input');
    toInput.type = 'date';
    toInput.className = 'tc-date-to';
    toInput.placeholder = 'To';

    const currentFilter = this.filters[column.field] || {};
    fromInput.value = currentFilter.from || '';
    toInput.value = currentFilter.to || '';

    const updateDateFilter = () => {
      const filter = {};
      if (fromInput.value) filter.from = fromInput.value;
      if (toInput.value) filter.to = toInput.value;
      
      this.setFilter(column.field, Object.keys(filter).length > 0 ? filter : null);
    };

    fromInput.addEventListener('change', updateDateFilter);
    toInput.addEventListener('change', updateDateFilter);

    container.appendChild(fromInput);
    container.appendChild(toInput);
    return container;
  }

  /**
   * Create number range filter
   */
  createNumberRangeFilter(column) {
    const container = document.createElement('div');
    container.className = 'tc-numberrange-container';

    const minInput = document.createElement('input');
    minInput.type = 'number';
    minInput.className = 'tc-number-min';
    minInput.placeholder = 'Min';

    const maxInput = document.createElement('input');
    maxInput.type = 'number';
    maxInput.className = 'tc-number-max';
    maxInput.placeholder = 'Max';

    const currentFilter = this.filters[column.field] || {};
    minInput.value = currentFilter.min || '';
    maxInput.value = currentFilter.max || '';

    const updateNumberFilter = () => {
      const filter = {};
      if (minInput.value) filter.min = parseFloat(minInput.value);
      if (maxInput.value) filter.max = parseFloat(maxInput.value);
      
      this.setFilter(column.field, Object.keys(filter).length > 0 ? filter : null);
    };

    minInput.addEventListener('input', updateNumberFilter);
    maxInput.addEventListener('input', updateNumberFilter);

    container.appendChild(minInput);
    container.appendChild(maxInput);
    return container;
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