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
    const breakpoint = this.getCurrentBreakpoint();
    return breakpoint === 'mobile';
  }

  /**
   * Toggle row selection for bulk operations
   */
  toggleRowSelection(rowIndex, selected) {
    if (selected) {
      this.selectedRows.add(rowIndex);
    } else {
      this.selectedRows.delete(rowIndex);
    }

    // Update bulk controls visibility
    this.updateBulkControls();

    // Call callback if provided
    if (this.config.onSelectionChange) {
      this.config.onSelectionChange({
        selectedRows: Array.from(this.selectedRows),
        totalSelected: this.selectedRows.size
      });
    }
  }

  /**
   * Select all visible rows
   */
  selectAllRows() {
    const displayData = this.getPaginatedData();
    displayData.forEach((row, index) => {
      const actualRowIndex = this.config.pagination ? 
        (this.currentPage - 1) * this.config.pageSize + index : 
        index;
      this.selectedRows.add(actualRowIndex);
    });
    
    this.updateBulkControls();
    this.render();
  }

  /**
   * Deselect all rows
   */
  deselectAllRows() {
    this.selectedRows.clear();
    this.updateBulkControls();
    this.render();
  }

  /**
   * Update bulk controls visibility and state
   */
  updateBulkControls() {
    const bulkControls = this.container.querySelector('.tc-bulk-controls');
    if (!bulkControls) return;

    const selectedCount = this.selectedRows.size;
    const bulkInfo = bulkControls.querySelector('.tc-bulk-info');
    
    if (selectedCount === 0) {
      bulkControls.style.display = 'none';
    } else {
      bulkControls.style.display = 'flex';
      if (bulkInfo) {
        bulkInfo.textContent = `${selectedCount} item${selectedCount === 1 ? '' : 's'} selected`;
      }
    }
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

    // Add bulk controls if enabled
    if (this.config.bulk.enabled) {
      wrapper.appendChild(this.renderBulkControls());
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
   * Get current breakpoint
   */
  getCurrentBreakpoint() {
    const width = window.innerWidth;
    const breakpoints = this.config.responsive.breakpoints || {
      mobile: { width: 480, layout: 'cards' },
      tablet: { width: 768, layout: 'compact' },
      desktop: { width: 1024, layout: 'table' }
    };

    if (width <= breakpoints.mobile.width) return 'mobile';
    if (width <= breakpoints.tablet.width) return 'tablet';
    return 'desktop';
  }

  /**
   * Get visible fields for current breakpoint
   */
  getVisibleFields(breakpoint) {
    const visibility = this.config.responsive.fieldVisibility || {};
    const breakpointConfig = visibility[breakpoint];
    
    if (!breakpointConfig) {
      return this.config.columns;
    }

    if (breakpointConfig.showFields) {
      return this.config.columns.filter(col => breakpointConfig.showFields.includes(col.field));
    }

    if (breakpointConfig.hideFields) {
      return this.config.columns.filter(col => !breakpointConfig.hideFields.includes(col.field));
    }

    return this.config.columns;
  }

  /**
   * Get hidden fields for current breakpoint
   */
  getHiddenFields(breakpoint) {
    const visibility = this.config.responsive.fieldVisibility || {};
    const breakpointConfig = visibility[breakpoint];
    
    if (!breakpointConfig) {
      return [];
    }

    if (breakpointConfig.hideFields) {
      return this.config.columns.filter(col => breakpointConfig.hideFields.includes(col.field));
    }

    if (breakpointConfig.showFields) {
      return this.config.columns.filter(col => !breakpointConfig.showFields.includes(col.field));
    }

    return [];
  }

  /**
   * Render cards view for mobile with expandable details
   */
  renderCards() {
    const cardsContainer = document.createElement('div');
    cardsContainer.className = 'tc-cards-container';

    const displayData = this.getPaginatedData();
    const breakpoint = this.getCurrentBreakpoint();
    const visibleFields = this.getVisibleFields(breakpoint);
    const hiddenFields = this.getHiddenFields(breakpoint);
    const hasHiddenFields = hiddenFields.length > 0;
    
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
        if (hasHiddenFields) {
          card.className += ' tc-card-expandable';
        }
        card.dataset.rowIndex = actualRowIndex;

        // Bulk selection checkbox if enabled
        if (this.config.bulk.enabled) {
          const checkboxContainer = document.createElement('div');
          checkboxContainer.className = 'tc-card-checkbox';
          
          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.className = 'tc-row-checkbox';
          checkbox.dataset.rowIndex = actualRowIndex;
          checkbox.checked = this.selectedRows.has(actualRowIndex);
          checkbox.addEventListener('change', (e) => {
            this.toggleRowSelection(actualRowIndex, e.target.checked);
          });
          
          checkboxContainer.appendChild(checkbox);
          card.appendChild(checkboxContainer);
        }

        // Card header with expand toggle
        const cardHeader = document.createElement('div');
        cardHeader.className = 'tc-card-header';
        
        // Use first column as title
        const firstColumn = this.config.columns[0];
        if (firstColumn) {
          const title = document.createElement('span');
          title.textContent = row[firstColumn.field] || `Item ${actualRowIndex + 1}`;
          cardHeader.appendChild(title);
        }

        // Add expand toggle if there are hidden fields
        if (hasHiddenFields) {
          const toggle = document.createElement('span');
          toggle.className = 'tc-card-toggle';
          toggle.textContent = '▼';
          cardHeader.appendChild(toggle);
          
          cardHeader.addEventListener('click', () => {
            this.toggleCard(card);
          });
          cardHeader.style.cursor = 'pointer';
        }
        
        card.appendChild(cardHeader);

        // Card body with visible fields
        const cardBody = document.createElement('div');
        cardBody.className = 'tc-card-body';

        visibleFields.forEach(column => {
          if (column === firstColumn) return; // Skip first column as it's in header
          
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

        // Hidden fields section (initially hidden)
        if (hasHiddenFields) {
          const hiddenSection = document.createElement('div');
          hiddenSection.className = 'tc-card-hidden-fields';

          hiddenFields.forEach(column => {
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
            hiddenSection.appendChild(field);
          });

          card.appendChild(hiddenSection);
        }

        cardsContainer.appendChild(card);
      });
    }

    return cardsContainer;
  }

  /**
   * Toggle card expansion
   */
  toggleCard(card) {
    const isExpanded = card.classList.contains('tc-card-expanded');
    
    if (isExpanded) {
      card.classList.remove('tc-card-expanded');
    } else {
      card.classList.add('tc-card-expanded');
    }
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
   * Render bulk controls
   */
  renderBulkControls() {
    const bulkContainer = document.createElement('div');
    bulkContainer.className = 'tc-bulk-controls';
    bulkContainer.style.display = 'none'; // Initially hidden

    // Bulk info
    const bulkInfo = document.createElement('div');
    bulkInfo.className = 'tc-bulk-info';
    bulkInfo.textContent = '0 items selected';

    // Select all checkbox
    const selectAllContainer = document.createElement('label');
    selectAllContainer.className = 'tc-bulk-select-all';
    
    const selectAllCheckbox = document.createElement('input');
    selectAllCheckbox.type = 'checkbox';
    selectAllCheckbox.addEventListener('change', (e) => {
      if (e.target.checked) {
        this.selectAllRows();
      } else {
        this.deselectAllRows();
      }
    });
    
    selectAllContainer.appendChild(selectAllCheckbox);
    selectAllContainer.appendChild(document.createTextNode(' Select All'));

    // Bulk actions
    const actionsContainer = document.createElement('div');
    actionsContainer.className = 'tc-bulk-actions';

    // Create action buttons based on configuration
    this.config.bulk.operations.forEach(operation => {
      const button = document.createElement('button');
      button.className = `tc-bulk-${operation}`;
      button.textContent = operation.charAt(0).toUpperCase() + operation.slice(1);
      button.addEventListener('click', () => this.performBulkAction(operation));
      actionsContainer.appendChild(button);
    });

    bulkContainer.appendChild(bulkInfo);
    bulkContainer.appendChild(selectAllContainer);
    bulkContainer.appendChild(actionsContainer);

    return bulkContainer;
  }

  /**
   * Perform bulk action on selected rows
   */
  performBulkAction(action) {
    const selectedRows = Array.from(this.selectedRows);
    if (selectedRows.length === 0) return;

    const selectedData = selectedRows.map(index => this.data[index]).filter(Boolean);

    switch (action) {
      case 'delete':
        this.bulkDelete(selectedRows, selectedData);
        break;
      case 'export':
        this.bulkExport(selectedData);
        break;
      case 'edit':
        this.bulkEdit(selectedRows, selectedData);
        break;
      default:
        // Call custom bulk action if provided
        if (this.config.onBulkAction) {
          this.config.onBulkAction({
            action: action,
            selectedRows: selectedRows,
            selectedData: selectedData
          });
        }
    }
  }

  /**
   * Bulk delete selected rows
   */
  bulkDelete(selectedRows, selectedData) {
    if (!confirm(`Are you sure you want to delete ${selectedRows.length} item${selectedRows.length === 1 ? '' : 's'}?`)) {
      return;
    }

    // Sort indices in descending order to remove from end first
    selectedRows.sort((a, b) => b - a);
    
    selectedRows.forEach(index => {
      this.data.splice(index, 1);
    });

    // Clear selection
    this.selectedRows.clear();
    this.updateBulkControls();
    this.render();

    // Call callback if provided
    if (this.config.onBulkDelete) {
      this.config.onBulkDelete({
        deletedRows: selectedRows,
        deletedData: selectedData
      });
    }
  }

  /**
   * Bulk export selected rows
   */
  bulkExport(selectedData) {
    const originalData = this.data;
    this.data = selectedData;
    
    try {
      this.downloadCSV();
    } finally {
      this.data = originalData;
    }

    // Call callback if provided
    if (this.config.onBulkExport) {
      this.config.onBulkExport({
        exportedData: selectedData
      });
    }
  }

  /**
   * Bulk edit selected rows
   */
  bulkEdit(selectedRows, selectedData) {
    // This could open a modal for bulk editing
    // For now, just call the callback
    if (this.config.onBulkEdit) {
      this.config.onBulkEdit({
        selectedRows: selectedRows,
        selectedData: selectedData
      });
    }
  }

  /**
   * Render add new entry button
   */
  renderAddNewButton() {
    if (!this.config.addNew.enabled) return null;

    const button = document.createElement('button');
    button.className = 'tc-add-new';
    button.textContent = 'Add New Entry';
    button.addEventListener('click', () => this.showAddNewModal());
    
    return button;
  }

  /**
   * Show add new entry modal
   */
  showAddNewModal() {
    const modal = this.createModal('Add New Entry', this.renderAddNewForm());
    document.body.appendChild(modal);
  }

  /**
   * Create modal structure
   */
  createModal(title, content) {
    const overlay = document.createElement('div');
    overlay.className = 'tc-modal-overlay';

    const modal = document.createElement('div');
    modal.className = 'tc-modal';

    // Header
    const header = document.createElement('div');
    header.className = 'tc-modal-header';
    
    const titleElement = document.createElement('h3');
    titleElement.className = 'tc-modal-title';
    titleElement.textContent = title;
    
    const closeButton = document.createElement('button');
    closeButton.className = 'tc-modal-close';
    closeButton.textContent = '×';
    closeButton.addEventListener('click', () => {
      document.body.removeChild(overlay);
    });
    
    header.appendChild(titleElement);
    header.appendChild(closeButton);

    // Content
    modal.appendChild(header);
    modal.appendChild(content);

    overlay.appendChild(modal);

    // Close on overlay click
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        document.body.removeChild(overlay);
      }
    });

    return overlay;
  }

  /**
   * Render add new entry form
   */
  renderAddNewForm() {
    const form = document.createElement('form');
    form.className = 'tc-modal-form';
    
    const fields = this.config.addNew.fields.length > 0 ? 
      this.config.addNew.fields : 
      this.config.columns.filter(col => col.field !== 'id');

    fields.forEach(field => {
      const fieldDiv = document.createElement('div');
      fieldDiv.className = 'tc-form-field';

      const label = document.createElement('label');
      label.className = 'tc-form-label';
      label.textContent = field.label || field.name;
      label.setAttribute('for', `tc-form-${field.field || field.name}`);

      const input = document.createElement('input');
      input.className = 'tc-form-input';
      input.type = field.type || 'text';
      input.id = `tc-form-${field.field || field.name}`;
      input.name = field.field || field.name;
      input.required = field.required || false;
      
      if (field.placeholder) {
        input.placeholder = field.placeholder;
      }

      fieldDiv.appendChild(label);
      fieldDiv.appendChild(input);
      form.appendChild(fieldDiv);
    });

    // Actions
    const actions = document.createElement('div');
    actions.className = 'tc-modal-actions';

    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'tc-btn-cancel';
    cancelButton.textContent = 'Cancel';
    cancelButton.addEventListener('click', () => {
      const overlay = form.closest('.tc-modal-overlay');
      document.body.removeChild(overlay);
    });

    const saveButton = document.createElement('button');
    saveButton.type = 'submit';
    saveButton.className = 'tc-btn-save';
    saveButton.textContent = 'Save';

    actions.appendChild(cancelButton);
    actions.appendChild(saveButton);
    form.appendChild(actions);

    // Handle form submission
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      this.handleAddNewSubmit(form);
    });

    return form;
  }

  /**
   * Handle add new entry form submission
   */
  handleAddNewSubmit(form) {
    const formData = new FormData(form);
    const newEntry = {};

    for (let [key, value] of formData.entries()) {
      newEntry[key] = value;
    }

    // Validate if validation rules provided
    const validation = this.config.addNew.validation || {};
    const errors = this.validateEntry(newEntry, validation);

    if (errors.length > 0) {
      this.showValidationErrors(form, errors);
      return;
    }

    // Add to data
    this.data.push(newEntry);
    
    // Close modal
    const overlay = form.closest('.tc-modal-overlay');
    document.body.removeChild(overlay);
    
    // Re-render
    this.render();

    // Call callback if provided
    if (this.config.onAdd) {
      this.config.onAdd({
        newEntry: newEntry,
        totalEntries: this.data.length
      });
    }
  }

  /**
   * Validate entry against rules
   */
  validateEntry(entry, rules) {
    const errors = [];

    Object.entries(rules).forEach(([field, rule]) => {
      const value = entry[field];

      if (rule.required && (!value || value.trim() === '')) {
        errors.push({ field, message: rule.message || `${field} is required` });
      }

      if (value && rule.type === 'email' && !this.isValidEmail(value)) {
        errors.push({ field, message: rule.message || 'Please enter a valid email address' });
      }

      if (value && rule.minLength && value.length < rule.minLength) {
        errors.push({ field, message: rule.message || `${field} must be at least ${rule.minLength} characters` });
      }

      if (value && rule.maxLength && value.length > rule.maxLength) {
        errors.push({ field, message: rule.message || `${field} must be no more than ${rule.maxLength} characters` });
      }
    });

    return errors;
  }

  /**
   * Show validation errors in form
   */
  showValidationErrors(form, errors) {
    // Clear existing errors
    form.querySelectorAll('.tc-form-error').forEach(error => error.remove());

    errors.forEach(error => {
      const field = form.querySelector(`[name="${error.field}"]`);
      if (field) {
        field.classList.add('tc-error');
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'tc-form-error';
        errorDiv.textContent = error.message;
        
        field.parentNode.appendChild(errorDiv);
      }
    });
  }

  /**
   * Validate email format
   */
  isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
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
    this.selectedRows.clear();
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