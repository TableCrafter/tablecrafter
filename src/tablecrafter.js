/**
 * TableCrafter - A lightweight, mobile-responsive data table library
 * @version 1.4.3
 * @author Fahad Murtaza
 * @license MIT
 */

class TableCrafter {
  constructor(container, config = {}) {
    console.log('TableCrafter: Initializing for', container);
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
      pageSize: 25,
      pagination: false,
      sortable: true,
      filterable: true,
      globalSearch: true,
      globalSearchPlaceholder: 'Search...',
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
      // Data validation configuration
      validation: {
        enabled: true,
        showErrors: true,
        validateOnEdit: true,
        validateOnSubmit: true,
        rules: {}, // Column-specific validation rules
        messages: {
          required: 'This field is required',
          email: 'Please enter a valid email address',
          minLength: 'Minimum length is {min} characters',
          maxLength: 'Maximum length is {max} characters',
          min: 'Minimum value is {min}',
          max: 'Maximum value is {max}',
          pattern: 'Please enter a valid format',
          custom: 'Validation failed'
        }
      },
      // Rich cell types configuration
      cellTypes: {
        text: { multiline: false },
        textarea: { rows: 3 },
        number: { step: 1, precision: 2 },
        email: { validation: true },
        date: { format: 'YYYY-MM-DD', showCalendar: true },
        datetime: { format: 'YYYY-MM-DDTHH:mm', showTime: true },
        select: { multiple: false, searchable: false },
        multiselect: { multiple: true, searchable: true },
        checkbox: { label: '' },
        radio: { orientation: 'horizontal' },
        file: { accept: '*/*', multiple: false, preview: true },
        url: { openInNewTab: true },
        color: { format: 'hex' },
        range: { min: 0, max: 100, step: 1 }
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
      // API integration configuration
      api: {
        baseUrl: '',
        endpoints: {
          data: '/data',
          create: '/create',
          update: '/update',
          delete: '/delete',
          lookup: '/lookup'
        },
        headers: {},
        authentication: null
      },
      // Permission system configuration
      permissions: {
        enabled: false,
        view: ['*'],
        edit: ['*'],
        delete: ['*'],
        create: ['*'],
        ownOnly: false
      },
      // State persistence configuration
      state: {
        persist: false,
        storage: 'localStorage',
        key: 'tablecrafter_state'
      },
      ...config
    };

    // Internal state
    this.data = [];
    this.currentPage = this.config.currentPage || 1;
    this.sortField = null;
    this.sortOrder = 'asc';
    this.filters = {};
    this.searchTerm = '';
    this.isLoading = false;
    this.editingCell = null;
    this.selectedRows = new Set();
    this.filterTypes = {};
    this.uniqueValues = {};
    this.lookupCache = new Map();
    this.currentUser = null;
    this.userPermissions = [];
    this.validationErrors = new Map(); // Track validation errors by cell
    this.validationRules = new Map(); // Compiled validation rules
    this.cellTypeRegistry = new Map(); // Rich cell type handlers
    this.activeEditors = new Map(); // Track active rich editors

    // Load state if persistence enabled
    this.loadState();

    // Initialize validation system
    this.initializeValidation();

    // Initialize rich cell types system
    this.initializeCellTypes();

    // Initialize if data provided or embedded in HTML
    const initialDataScript = this.container.querySelector('.tc-initial-data');
    if (initialDataScript) {
      try {
        this.data = JSON.parse(initialDataScript.textContent);
        this.autoDiscoverColumns();
        console.log('TableCrafter: Initialized from embedded data payload');
      } catch (e) {
        console.error('TableCrafter: Failed to parse embedded data', e);
      }
    }

    if (this.data.length === 0 && this.config.data) {
      if (Array.isArray(this.config.data)) {
        this.data = [...this.config.data];
        this.autoDiscoverColumns();
        this.render();
      } else if (typeof this.config.data === 'string') {
        // URL provided, will load asynchronously
        this.dataUrl = this.config.data;
        this.loadData().catch(err => {
            console.error('TableCrafter: Initial load failed', err);
            this.renderError('Unable to load data. Please check your connection.');
        });
      }
    } else if (this.data.length > 0 && typeof this.config.data === 'string') {
      // Data was embedded, but we still store the URL for potential refreshes
      this.dataUrl = this.config.data;
      this.render();
    } else if (this.data.length > 0) {
      // Data present but no URL
      this.render();
    }

    // Bind resize handler if responsive
    if (this.config.responsive) {
      this.handleResize = this.handleResize.bind(this);
      window.addEventListener('resize', this.handleResize);
    }
  }

  /**
   * Format value for display
   */
  formatValue(value, type) {
    if (value === null || value === undefined) return '';
    
    // Auto-detect if not specified
    if (!type) {
      type = this.detectDataType(value);
    }

    switch (type) {
      case 'date':
        // Try to parse date
        const date = new Date(value);
        if (isNaN(date.getTime())) return value;
        
        return date.toLocaleDateString(undefined, {
          year: 'numeric',
          month: 'short',
          day: 'numeric'
        });
      
      case 'datetime':
        const dt = new Date(value);
        if (isNaN(dt.getTime())) return value;

        return dt.toLocaleString(undefined, {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });

      case 'boolean':
        const isTrue = value === true || value === 'true' || value === 1 || value === '1';
        return isTrue 
          ? '<span class="tc-badge tc-badge-success">Yes</span>' 
          : '<span class="tc-badge tc-badge-error">No</span>';

      case 'email':
        return `<a href="mailto:${value}" class="tc-link">${value}</a>`;

      case 'url':
        let url = value.toString();
        // Ensure protocol
        if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
        // Truncate for display
        const displayUrl = value.length > 30 ? value.substring(0, 27) + '...' : value;
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="tc-link">${displayUrl}</a>`;

      case 'image':
         return `<img src="${value}" alt="Image" class="tc-cell-image" style="max-height: 50px; border-radius: 4px;">`;

      default:
        // Basic XSS protection for unknown types if it's a string
        if (typeof value === 'string') {
             return value;
        }
        return value.toString();
    }
  }

  /**
   * Detect data type from value
   */
  detectDataType(value) {
    if (value === null || value === undefined) return 'text';
    
    // Check Boolean
    if (typeof value === 'boolean' || value === 'true' || value === 'false') return 'boolean';

    // Check String formats
    if (typeof value === 'string') {
      if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'email';
      if (/^https?:\/\/[^\s]+$/i.test(value)) {
         return /\.(jpg|jpeg|png|gif|webp)$/i.test(value) ? 'image' : 'url';
      }
      // ISO Date Check (YYYY-MM-DD or YYYY-MM-DDTHH:mm:ss)
      if (/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d{3})?Z?)?$/.test(value)) {
          const d = new Date(value);
          return !isNaN(d.getTime()) ? 'date' : 'text';
      }
    }

    return 'text';
  }

  /**
   * Debounce Utility.
   * Prevents rapid firing of expensive operations.
   * 
   * @param {Function} func The function to debounce.
   * @param {number} wait Delay in milliseconds.
   */
  debounce(func, wait) {
    let timeout;
    return function (...args) {
      const context = this;
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(context, args), wait);
    };
  }

  /**
   * Resolve container from selector or element
   */
  resolveContainer(container) {
    if (typeof container === 'string') {
      return document.querySelector(container);
    } else if (container && container.nodeType === 1) { // Check for Element nodeType instead of instanceof
      return container;
    }
    return null;
  }

  /**
   * Load data from URL
   */
  async loadData() {
    this.isLoading = true;
    this.renderLoading();

    // If SSR mode is enabled and content exists, handle hydration logic
    if (this.container.dataset.ssr === "true") {
      // this.render(); // <-- REMOVED: Do not wipe server content yet!
      if (this.data && this.data.length > 0) {
        this.container.dataset.ssr = "false";
        this.hydrateListeners(); // Attach listeners to existing DOM
        this.isLoading = false;
        return Promise.resolve(this.data);
      }
      if (this.dataUrl) {
         try {
           const response = await fetch(this.dataUrl);
           if (!response.ok) throw new Error(`HTTP ${response.status}`);
           const data = await response.json();
           this.data = this.processData(data);
           this.autoDiscoverColumns();
           this.detectFilterTypes();
           this.container.dataset.ssr = "false";
           this.render();
         } catch (e) {
           console.error('TableCrafter: Hydration failed', e);
           // Silent fail for hydration is okay, user sees SSR content
         }
      }
      this.isLoading = false;
      return this.data;
    }

    // Standard Client-Side Load
    try {
      const response = await fetch(this.dataUrl);
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json();
      this.data = this.processData(data); // Using processData for consistency
      
      this.autoDiscoverColumns();
      this.render();
    } catch (error) {
      console.error('TableCrafter: Load failed', error);
      this.renderError('Unable to load data. The source may be unavailable.');
      throw error;
    } finally {
      this.isLoading = false;
    }
  }

  renderLoading() {
      if (!this.container) return;
      // Do not show skeleton if we have SSR content that hasn't been hydrated yet
      if (this.container.dataset.ssr === "true" && this.container.children.length > 0) {
          return;
      }
      
      // improved skeleton loading
      const skeletonRows = Array(5).fill(0).map(() => `
          <div class="tc-skeleton-row">
              <div class="tc-skeleton-cell tc-skeleton"></div>
              <div class="tc-skeleton-cell tc-skeleton"></div>
              <div class="tc-skeleton-cell tc-skeleton"></div>
              <div class="tc-skeleton-cell tc-skeleton"></div>
              <div class="tc-skeleton-cell tc-skeleton"></div>
          </div>
      `).join('');

      this.container.innerHTML = `
          <div class="tc-wrapper">
              <div class="tc-loading-container">
                  ${skeletonRows}
              </div>
          </div>
      `;
  }

  renderError(message) {
      this.container.innerHTML = `
        <div class="tc-error-container">
          <div class="tc-error-message">${message}</div>
          <button class="tc-retry-button">Retry</button>
        </div>
      `;
      
      const retryBtn = this.container.querySelector('.tc-retry-button');
      if (retryBtn) {
        retryBtn.addEventListener('click', () => {
          this.renderLoading();
          this.loadData().catch(err => {
               console.error('TableCrafter: Retry failed', err);
               this.renderError('Retry failed. Please try again later.');
          });
        });
      }
        }



  /**
   * Process and normalize data based on configuration (root path, etc.)
   */
  processData(data) {
    if (!data) return [];

    // Handle nested data path (root)
    const root = this.config.root || this.config.dataRoot;
    if (root) {
      const path = root.split('.');
      for (const segment of path) {
        if (data && typeof data === 'object' && segment in data) {
          data = data[segment];
        } else {
          console.warn(`TableCrafter: Path segment "${segment}" not found in data`, data);
          return [];
        }
      }
    }

    return Array.isArray(data) ? data : (data ? [data] : []);
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
   * Auto-discover columns from data
   */
  autoDiscoverColumns() {
    if (this.data.length > 0 && this.config.columns.length === 0) {
      const firstItem = this.data[0];
      let keys = Object.keys(firstItem);

      // Apply include/exclude rules
      const include = this.config.include ?
        (Array.isArray(this.config.include) ? this.config.include : this.config.include.split(',').map(s => s.trim())) :
        null;
      const exclude = this.config.exclude ?
        (Array.isArray(this.config.exclude) ? this.config.exclude : this.config.exclude.split(',').map(s => s.trim())) :
        [];

      if (include) {
        keys = keys.filter(key => include.includes(key));
        // Sort keys to match include order
        keys.sort((a, b) => include.indexOf(a) - include.indexOf(b));
      }

      if (exclude.length > 0) {
        keys = keys.filter(key => !exclude.includes(key));
      }

      this.config.columns = keys.map(key => ({
        field: key,
        label: key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' '),
        sortable: true
      }));
    }
  }

  render() {
    // Check if we are hydrating (SSR content already present)
    const isHydrating = this.container.dataset.ssr === "true" &&
      (this.container.querySelector('table') || this.container.querySelector('.tc-cards-container') || this.container.querySelector('.tc-loading') || this.container.querySelector('.tc-wrapper'));

    let wrapper;
    if (isHydrating) {
      // If hydrating, we don't clear the container. 
      // Instead, we ensure the .tc-wrapper exists and wraps the content.
      wrapper = this.container.querySelector('.tc-wrapper');
      if (!wrapper) {
        wrapper = document.createElement('div');
        wrapper.className = 'tc-wrapper';

        // Move all existing children into the wrapper
        while (this.container.firstChild) {
          wrapper.appendChild(this.container.firstChild);
        }
        this.container.appendChild(wrapper);
      }

      // Remove any existing tools to avoid duplicates
      const tools = wrapper.querySelectorAll('.tc-global-search-container, .tc-filters, .tc-bulk-controls, .tc-export-controls, .tc-pagination');
      tools.forEach(tool => tool.remove());
    } else {
      // Standard render: clear and rebuild
      this.container.innerHTML = '';
      wrapper = document.createElement('div');
      wrapper.className = 'tc-wrapper';
      this.container.appendChild(wrapper);
    }

    // Add global search if enabled
    if (this.config.globalSearch) {
      const searchContainer = this.renderGlobalSearch();
      if (isHydrating) {
        wrapper.insertBefore(searchContainer, wrapper.firstChild);
      } else {
        wrapper.appendChild(searchContainer);
      }
    }

    // Add filters if enabled
    if (this.config.filterable) {
      const filters = this.renderFilters();
      if (filters) {
        if (isHydrating) {
          // If search was added, insert filters after it. Otherwise insert at beginning.
          const search = wrapper.querySelector('.tc-global-search-container');
          if (search && search.nextSibling) {
            wrapper.insertBefore(filters, search.nextSibling);
          } else {
            wrapper.insertBefore(filters, wrapper.firstChild);
          }
        } else {
          wrapper.appendChild(filters);
        }
      }
    }

    // Add bulk controls if enabled
    if (this.config.bulk.enabled) {
      wrapper.appendChild(this.renderBulkControls());
    }

    // Add export controls if enabled
    if (this.config.exportable) {
      const exportTools = this.renderExportControls();
      if (isHydrating) {
        // Find existing tools area or insert after table/cards
        const target = wrapper.querySelector('.tc-table-container, .tc-cards-container') || wrapper.firstChild;
        wrapper.insertBefore(exportTools, target);
      } else {
        wrapper.appendChild(exportTools);
      }
    }

    // Render data view if not hydrating
    if (!isHydrating) {
      if (this.config.responsive && this.isMobile()) {
        wrapper.appendChild(this.renderCards());
      } else {
        wrapper.appendChild(this.renderTable());
      }
    }

    // Add pagination if enabled and needed
    if (this.config.pagination && this.shouldShowPagination()) {
      wrapper.appendChild(this.renderPagination());
    }
  }

  /**
   * Hydrate listeners for server-rendered content
   */
  hydrateListeners() {
    const table = this.container.querySelector('table.tc-table');
    if (!table) return;

    // Hydrate Sort Headers
    if (this.config.sortable) {
        const headers = table.querySelectorAll('th.tc-sortable');
        headers.forEach((th, index) => {
            // Get field from data attribute or fallback to config
            const field = th.dataset.field || (this.config.columns[index] ? this.config.columns[index].field : null);
            
            if (field) {
                // Remove old listeners if any (cloning to be safe or just add new ones)
                // Note: In hydration we assume fresh DOM
                th.addEventListener('click', () => this.sort(field));
                th.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.sort(field);
                    }
                });
            }
        });
    }

    // Hydrate Filters (if they exist in DOM)
    // For now, PHP only renders the table, filters are usually JS-only or need separate hydration logic.
    // If we wanted to hydrate filters, we'd do it here. 
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
      th.setAttribute('scope', 'col');
      th.textContent = column.label;
      th.dataset.field = column.field;

      if (this.config.sortable && column.sortable !== false) {
        th.className = 'tc-sortable';
        th.tabIndex = 0; // Make focusable
        
        // helper to get aria-sort state
        let sortState = 'none';
        if (this.sortField === column.field) {
            sortState = this.sortOrder === 'asc' ? 'ascending' : 'descending';
        }
        th.setAttribute('aria-sort', sortState);

        // Click handler
        th.addEventListener('click', () => this.sort(column.field));
        
        // Keyboard handler (Enter/Space)
        th.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.sort(column.field);
            }
        });
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

        const columnPromises = this.config.columns.map(async (column) => {
          const td = document.createElement('td');

          // Format lookup values
          let displayValue = row[column.field];
          
          if (displayValue === null || displayValue === undefined) {
             displayValue = '';
          }

          if (column.lookup && displayValue) {
            displayValue = await this.formatLookupValue(column, displayValue);
            td.textContent = displayValue;
          } else {
             // Auto-format value
             const formatted = this.formatValue(displayValue, column.type);
             
             // Check if formatted result is HTML (simple check: contains tags)
             if (typeof formatted === 'string' && /<[a-z][\s\S]*>/i.test(formatted)) {
                td.innerHTML = formatted;
             } else {
                td.textContent = formatted;
             }
          }
          td.dataset.field = column.field;

          // Make cell editable if configured and user has permission
          if (this.config.editable && column.editable && this.hasPermission('edit', row)) {
            td.className = 'tc-editable';
            td.addEventListener('click', (e) => this.startEdit(e, actualRowIndex, column.field));
          }

          tr.appendChild(td);
        });

        // We don't necessarily need to await them all here since they append to tr
        // but it's cleaner to handle them.
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
    const defaults = {
      mobile: { width: 480, layout: 'cards' },
      tablet: { width: 768, layout: 'compact' },
      desktop: { width: 1024, layout: 'table' }
    };
    const breakpoints = { ...defaults, ...(this.config.responsive.breakpoints || {}) };

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
    cardsContainer.setAttribute('role', 'list');

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
        card.setAttribute('role', 'listitem');
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

          // Format lookup values
          let displayValue = row[column.field] || '';
          if (column.lookup && displayValue) {
            this.formatLookupValue(column, displayValue).then(formatted => {
              value.textContent = formatted;
            });
          } else {
            value.textContent = displayValue;
          }

          value.dataset.field = column.field;

          // Make field editable if configured and user has permission
          if (this.config.editable && column.editable && this.hasPermission('edit', row)) {
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

            // Format lookup values
            let displayValue = row[column.field] || '';
            if (column.lookup && displayValue) {
              this.formatLookupValue(column, displayValue).then(formatted => {
                value.textContent = formatted;
              });
            } else {
              value.textContent = displayValue;
            }

            value.dataset.field = column.field;

            // Make field editable if configured and user has permission
            if (this.config.editable && column.editable && this.hasPermission('edit', row)) {
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
  async startEdit(event, rowIndex, field) {
    const target = event.currentTarget;

    // Check permissions
    if (!this.hasPermission('edit', this.data[rowIndex])) {
      return;
    }

    // Don't start edit if already editing
    if (this.editingCell === target) {
      return;
    }

    // Cancel any existing edit
    if (this.editingCell) {
      this.cancelEdit();
    }

    const currentValue = this.data[rowIndex][field];
    const column = this.config.columns.find(col => col.field === field);

    let editElement;

    // Create appropriate edit control based on field type
    if (column && column.lookup) {
      // Create lookup dropdown
      editElement = await this.createLookupDropdown(column, currentValue);
      editElement.className = 'tc-edit-select';
    } else if (column && column.type && this.cellTypeRegistry.has(column.type)) {
      // Create rich cell type editor
      editElement = await this.createRichCellEditor(column, currentValue, rowIndex);
    } else {
      // Create regular input
      editElement = document.createElement('input');
      editElement.type = column?.type || 'text';
      editElement.value = currentValue || '';
      editElement.className = 'tc-edit-input';
    }

    // Store original value and metadata
    editElement.dataset.originalValue = currentValue || '';
    editElement.dataset.rowIndex = rowIndex;
    editElement.dataset.field = field;

    // Replace content with edit element
    target.innerHTML = '';
    target.appendChild(editElement);

    // Focus the element
    editElement.focus();
    if (editElement.select) {
      editElement.select();
    }

    // Set current editing cell
    this.editingCell = target;

    // Handle blur/change events
    if (editElement.tagName === 'SELECT') {
      editElement.addEventListener('change', () => this.saveEdit(editElement));
      editElement.addEventListener('blur', () => this.saveEdit(editElement));
    } else {
      editElement.addEventListener('blur', () => this.saveEdit(editElement));
    }

    // Handle Enter/Escape keys
    editElement.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        this.saveEdit(editElement);
      } else if (e.key === 'Escape') {
        this.cancelEdit();
      }
    });
  }

  /**
   * Save edited value
   */
  async saveEdit(element) {
    const rowIndex = parseInt(element.dataset.rowIndex);
    const field = element.dataset.field;
    const oldValue = element.dataset.originalValue;

    // Get new value based on element type
    let newValue;
    if (element.getValue && typeof element.getValue === 'function') {
      // Rich cell type with custom getValue method
      newValue = element.getValue();
    } else if (element.type === 'checkbox') {
      newValue = element.checked;
    } else if (element.type === 'file') {
      newValue = element.files.length > 0 ? element.files[0].name : oldValue;
    } else {
      newValue = element.value;
    }

    // Validate the new value
    if (this.config.validation.enabled && this.config.validation.validateOnEdit) {
      const validation = this.validateField(field, newValue, this.data[rowIndex]);

      if (!validation.isValid) {
        // Show validation errors
        this.showValidationError(element, validation.errors);
        this.setValidationError(rowIndex, field, validation.errors);

        // Don't save invalid data
        element.value = oldValue; // Revert to original value
        element.focus();
        return;
      } else {
        // Clear any existing validation errors
        this.clearValidationError(element);
        this.setValidationError(rowIndex, field, []);
      }
    }

    // Update data
    this.data[rowIndex][field] = newValue;

    // Update via API if configured
    if (this.config.api.baseUrl) {
      try {
        await this.updateEntry(rowIndex, { [field]: newValue });
      } catch (error) {
        // Revert on error
        this.data[rowIndex][field] = oldValue;
        alert('Failed to save changes: ' + error.message);
        this.cancelEdit();
        return;
      }
    }

    // Call onEdit callback if provided
    if (this.config.onEdit) {
      this.config.onEdit({
        row: rowIndex,
        field: field,
        oldValue: oldValue,
        newValue: newValue
      });
    }

    // Update display with formatted value
    const parent = element.parentElement;
    const column = this.config.columns.find(col => col.field === field);

    if (column && column.lookup) {
      // Format lookup value for display
      const displayValue = await this.formatLookupValue(column, newValue);
      parent.textContent = displayValue;
    } else {
      parent.textContent = newValue;
    }

    // Clear editing state
    this.editingCell = null;
  }

  /**
   * Cancel editing
   */
  cancelEdit() {
    if (!this.editingCell) return;

    const element = this.editingCell.querySelector('input, select');
    if (element) {
      this.editingCell.textContent = element.dataset.originalValue;
    }

    this.editingCell = null;
  }

  /**
   * Get filtered data with advanced filtering support
   */
  getFilteredData() {
    // Apply permission filtering first
    let data = this.getPermissionFilteredData();

    if (!this.config.filterable && !this.config.globalSearch) {
      return data;
    }

    // Apply global search filter
    if (this.config.globalSearch && this.searchTerm) {
      const searchLower = this.searchTerm.toLowerCase();
      data = data.filter(row => {
        return Object.values(row).some(val => {
          if (val === null || val === undefined) return false;
          return val.toString().toLowerCase().includes(searchLower);
        });
      });
    }

    // Apply column filters
    if (Object.keys(this.filters).length === 0) {
      return data;
    }

    return data.filter(row => {
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
      this.saveState();
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
        if (this.isDateField(values) && !/sku|id|ref|code|serial|part/i.test(field)) {
          this.filterTypes[field] = 'daterange';
        }
        // Check if it's numeric
        else if (this.isNumericField(values)) {
          this.filterTypes[field] = 'numberrange';
        }
        // Check if it should be a multiselect (limited unique values)
        // Skip common text fields (name, email, etc)
        else if (this.uniqueValues[field].length <= 20 &&
          this.uniqueValues[field].length > 1 &&
          !/name|email|title|desc|phone|address|subject/i.test(field)) {
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
      /^\d{4}-\d{2}-\d{2}$/, // YYYY-MM-DD
      /^\d{2}\/\d{2}\/\d{4}$/, // MM/DD/YYYY
      /^\d{2}-\d{2}-\d{4}$/, // MM-DD-YYYY
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/ // ISO
    ];

    return values.length > 0 && values.slice(0, 5).every(val => {
      if (!val) return false;
      const str = val.toString();
      // Must match strict pattern OR be a valid date parse that is NOT a number
      // Also ensure it's not too short (Date.parse is very aggressive)
      return datePatterns.some(pattern => pattern.test(str)) ||
        (str.length > 6 && !isNaN(Date.parse(str)) && isNaN(Number(str)));
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
    if (!this.config.filterable) return null;

    const filtersContainer = document.createElement('div');
    filtersContainer.className = 'tc-filters';

    // 1. Clear All Button
    if (this.config.filters.showClearAll) {
      const clearAllBtn = document.createElement('button');
      clearAllBtn.className = 'tc-clear-filters';
      clearAllBtn.textContent = 'Clear All Filters';
      clearAllBtn.addEventListener('click', () => this.clearFilters());
      
      // Styling enhancements
      clearAllBtn.style.padding = '6px 12px';
      clearAllBtn.style.marginBottom = '10px';
      clearAllBtn.style.backgroundColor = '#d63638';
      clearAllBtn.style.color = '#fff';
      clearAllBtn.style.border = '1px solid #d63638';
      clearAllBtn.style.borderRadius = '4px';
      clearAllBtn.style.cursor = 'pointer';
      clearAllBtn.style.fontSize = '12px';

      filtersContainer.appendChild(clearAllBtn);
    }

    // 2. Specific Column Filters
    this.detectFilterTypes();
    const filterRow = document.createElement('div');
    filterRow.className = 'tc-filters-row';

    this.config.columns.forEach(column => {
      // In advanced mode, we show all columns that aren't explicitly excluded
      if (column.filterable !== false) {
        const filterType = this.filterTypes[column.field] || 'text';
        const filterDiv = this.createFilterControl(column, filterType);
        filterRow.appendChild(filterDiv);
      }
    });
    filtersContainer.appendChild(filterRow);

    return filtersContainer;
  }

  /**
   * Render global search bar
   */
  renderGlobalSearch() {
    const searchContainer = document.createElement('div');
    searchContainer.className = 'tc-global-search-container';

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'tc-global-search';
    searchInput.setAttribute('aria-label', 'Search table');
    searchInput.placeholder = this.config.globalSearchPlaceholder || 'Search table...';
    searchInput.value = this.searchTerm;

    const debouncedSearch = this.debounce((value) => {
      this.searchTerm = value;
      this.currentPage = 1;
      this.render();
    }, 300);

    searchInput.addEventListener('input', (e) => debouncedSearch(e.target.value));

    // Styling enhancements
    searchInput.style.padding = '8px 12px';
    searchInput.style.marginBottom = '15px';
    searchInput.style.width = '100%';
    searchInput.style.maxWidth = '400px';
    searchInput.style.border = '1px solid #ddd';
    searchInput.style.borderRadius = '4px';

    searchContainer.appendChild(searchInput);
    return searchContainer;
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

    input.addEventListener('input', this.debounce((e) => {
      this.setFilter(column.field, e.target.value);
    }, 300));

    return input;
  }

  /**
   * Create multiselect filter dropdown
   */
  createMultiselectFilter(column) {
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
        this.updateMultiselectFilter(column.field, dropdown, button);
      });

      option.appendChild(checkbox);
      option.appendChild(document.createTextNode(value));
      dropdown.appendChild(option);
    });

    // Toggle logic with Fixed Positioning (Popover)
    const toggleDropdown = (e) => {
      e.stopPropagation();
      const isHidden = dropdown.style.display === 'none';

      // Close all other dropdowns
      document.querySelectorAll('.tc-multiselect-dropdown').forEach(d => d.style.display = 'none');

      if (isHidden) {
        dropdown.style.display = 'block';
        dropdown.style.position = 'fixed';
        dropdown.style.zIndex = '10000'; // High z-index

        const rect = button.getBoundingClientRect();
        dropdown.style.top = (rect.bottom) + 'px';
        dropdown.style.left = rect.left + 'px';
        dropdown.style.width = rect.width + 'px';
        dropdown.style.maxHeight = '300px'; // Ensure visibility

        // Add global listeners
        document.addEventListener('click', closeDropdown);
        window.addEventListener('scroll', closeDropdown, { capture: true });
      } else {
        closeDropdown();
      }
    };

    const closeDropdown = (e) => {
      if (e && (dropdown.contains(e.target) || e.target === button)) return;
      dropdown.style.display = 'none';
      document.removeEventListener('click', closeDropdown);
      window.removeEventListener('scroll', closeDropdown, { capture: true });
    };

    button.addEventListener('click', toggleDropdown);

    // Initial setup
    this.updateMultiselectButton(button, currentFilter);

    // Append to body and track
    document.body.appendChild(dropdown);
    if (!this.dropdowns) this.dropdowns = [];
    this.dropdowns.push(dropdown);

    return button;
  }

  /**
   * Update multiselect filter based on checkbox changes
   */
  updateMultiselectFilter(field, dropdown, button) {
    const checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
    const selectedValues = Array.from(checkboxes)
      .filter(cb => cb.checked)
      .map(cb => cb.value);

    this.setFilter(field, selectedValues);

    // Update button text
    if (button) {
      this.updateMultiselectButton(button, selectedValues);
    }
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

    const debouncedUpdate = this.debounce(updateNumberFilter, 300);
    minInput.addEventListener('input', debouncedUpdate);
    maxInput.addEventListener('input', debouncedUpdate);

    container.appendChild(minInput);
    container.appendChild(maxInput);
    return container;
  }

  /**
   * Set filter for a field
   */
  setFilter(field, value) {
    if (!value || (typeof value === 'string' && value.trim() === '') || (Array.isArray(value) && value.length === 0)) {
      delete this.filters[field];
    } else {
      this.filters[field] = typeof value === 'string' ? value.trim() : value;
    }

    // Reset to first page when filtering
    this.currentPage = 1;

    // Save state if persistence enabled
    this.saveState();

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
    this.saveState();
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

    const copyBtn = document.createElement('button');
    copyBtn.className = 'tc-copy-clipboard';
    copyBtn.textContent = 'Copy to Clipboard';
    copyBtn.style.marginLeft = '8px';
    copyBtn.addEventListener('click', () => this.copyToClipboard());
    exportContainer.appendChild(copyBtn);

    return exportContainer;
  }

  /**
   * Copy table data to clipboard
   */
  copyToClipboard() {
    const exportableData = this.getExportableData();
    const exportableColumns = this.getExportableColumns();

    if (exportableData.length === 0) return;

    // Create tab-separated text for spreadsheets
    const header = exportableColumns.map(col => col.label).join('\t');
    const rows = exportableData.map(row => {
      return exportableColumns.map(col => row[col.field]).join('\t');
    }).join('\n');

    const text = header + '\n' + rows;

    const onSuccess = () => {
      const btn = this.container.querySelector('.tc-copy-clipboard');
      if (btn) {
        const originalText = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('tc-copy-success');
        setTimeout(() => {
          btn.textContent = originalText;
          btn.classList.remove('tc-copy-success');
        }, 2000);
      }
    };

    const onError = (err) => {
      console.error('Failed to copy: ', err);
      // Fallback method
      const textArea = document.createElement('textarea');
      textArea.value = text;
      textArea.style.position = 'fixed';
      textArea.style.left = '-9999px';
      textArea.style.top = '0';
      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();
      try {
        const successful = document.execCommand('copy');
        if (successful) onSuccess();
      } catch (err) {
        console.error('Fallback copy failed: ', err);
      }
      document.body.removeChild(textArea);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(onSuccess).catch(onError);
    } else {
      onError('Clipboard API unavailable');
    }
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
    this.saveState();
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

    // Validate using the new validation system
    if (this.config.validation.enabled && this.config.validation.validateOnSubmit) {
      const validation = this.validateRow(newEntry, -1); // -1 for new entry

      if (!validation.isValid) {
        this.showFormValidationErrors(form, validation.errors);
        return;
      }
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
   * API Integration Methods
   */

  /**
   * Make API request with authentication and error handling
   */
  async apiRequest(endpoint, options = {}) {
    const config = this.config.api;
    const url = config.baseUrl + endpoint;

    const requestOptions = {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        ...config.headers
      },
      ...options
    };

    // Add authentication if configured
    if (config.authentication) {
      if (config.authentication.type === 'bearer') {
        requestOptions.headers['Authorization'] = `Bearer ${config.authentication.token}`;
      } else if (config.authentication.type === 'api-key') {
        requestOptions.headers[config.authentication.headerName] = config.authentication.key;
      }
    }

    try {
      const response = await fetch(url, requestOptions);

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      return await response.json();
    } catch (error) {
      console.error('API request failed:', error);
      throw error;
    }
  }

  /**
   * Load data from API
   */
  async loadDataFromAPI() {
    if (!this.config.api.baseUrl) {
      throw new Error('API base URL not configured');
    }

    try {
      this.isLoading = true;
      const data = await this.apiRequest(this.config.api.endpoints.data);
      this.data = Array.isArray(data) ? data : data.data || [];
      this.isLoading = false;

      if (this.container.querySelector('.tc-wrapper')) {
        this.render();
      }

      return this.data;
    } catch (error) {
      this.isLoading = false;
      throw error;
    }
  }

  /**
   * Create new entry via API
   */
  async createEntry(entryData) {
    if (!this.config.api.baseUrl) {
      // Fall back to local creation
      this.data.push(entryData);
      return entryData;
    }

    try {
      const response = await this.apiRequest(this.config.api.endpoints.create, {
        method: 'POST',
        body: JSON.stringify(entryData)
      });

      // Add to local data
      this.data.push(response);
      return response;
    } catch (error) {
      console.error('Failed to create entry:', error);
      throw error;
    }
  }

  /**
   * Update entry via API
   */
  async updateEntry(index, entryData) {
    const originalEntry = this.data[index];

    if (!this.config.api.baseUrl) {
      // Fall back to local update
      this.data[index] = { ...originalEntry, ...entryData };
      return this.data[index];
    }

    try {
      const response = await this.apiRequest(
        `${this.config.api.endpoints.update}/${originalEntry.id || index}`,
        {
          method: 'PUT',
          body: JSON.stringify(entryData)
        }
      );

      // Update local data
      this.data[index] = response;
      return response;
    } catch (error) {
      console.error('Failed to update entry:', error);
      throw error;
    }
  }

  /**
   * Delete entry via API
   */
  async deleteEntry(index) {
    const entry = this.data[index];

    if (!this.config.api.baseUrl) {
      // Fall back to local deletion
      this.data.splice(index, 1);
      return true;
    }

    try {
      await this.apiRequest(
        `${this.config.api.endpoints.delete}/${entry.id || index}`,
        { method: 'DELETE' }
      );

      // Remove from local data
      this.data.splice(index, 1);
      return true;
    } catch (error) {
      console.error('Failed to delete entry:', error);
      throw error;
    }
  }

  /**
   * Lookup Fields System
   */

  /**
   * Load lookup data for a field
   */
  async loadLookupData(field, lookupConfig) {
    const cacheKey = `${field}_${JSON.stringify(lookupConfig)}`;

    // Check cache first
    if (this.lookupCache.has(cacheKey)) {
      return this.lookupCache.get(cacheKey);
    }

    try {
      let data;

      if (lookupConfig.url) {
        // Load from custom URL
        const response = await fetch(lookupConfig.url);
        data = await response.json();
      } else if (lookupConfig.type && this.config.api.baseUrl) {
        // Load from API endpoint
        const endpoint = `${this.config.api.endpoints.lookup}/${lookupConfig.type}`;
        data = await this.apiRequest(endpoint);
      } else if (lookupConfig.data) {
        // Use provided static data
        data = lookupConfig.data;
      } else {
        throw new Error('No lookup data source configured');
      }

      // Apply filters if specified
      if (lookupConfig.filter) {
        data = data.filter(item => {
          return Object.entries(lookupConfig.filter).every(([key, value]) => {
            return item[key] === value;
          });
        });
      }

      // Cache the result
      this.lookupCache.set(cacheKey, data);
      return data;
    } catch (error) {
      console.error('Failed to load lookup data:', error);
      return [];
    }
  }

  /**
   * Create lookup dropdown for editing
   */
  async createLookupDropdown(column, currentValue) {
    const lookupConfig = column.lookup;
    if (!lookupConfig) return null;

    const data = await this.loadLookupData(column.field, lookupConfig);

    const select = document.createElement('select');
    select.className = 'tc-lookup-select';

    // Add empty option
    const emptyOption = document.createElement('option');
    emptyOption.value = '';
    emptyOption.textContent = 'Select...';
    select.appendChild(emptyOption);

    // Add options from lookup data
    data.forEach(item => {
      const option = document.createElement('option');
      option.value = item[lookupConfig.valueField || 'id'];
      option.textContent = item[lookupConfig.displayField || 'name'];

      if (option.value == currentValue) {
        option.selected = true;
      }

      select.appendChild(option);
    });

    return select;
  }

  /**
   * Format lookup field display value
   */
  async formatLookupValue(column, value) {
    if (!value || !column.lookup) return value;

    const lookupConfig = column.lookup;
    const data = await this.loadLookupData(column.field, lookupConfig);

    const item = data.find(item =>
      item[lookupConfig.valueField || 'id'] == value
    );

    return item ? item[lookupConfig.displayField || 'name'] : value;
  }

  /**
   * Permission System
   */

  /**
   * Set current user context
   */
  setCurrentUser(user) {
    this.currentUser = user;
    this.userPermissions = user.roles || user.permissions || [];
  }

  /**
   * Check if user has permission for action
   */
  hasPermission(action, entry = null) {
    if (!this.config.permissions.enabled) {
      return true;
    }

    const permissions = this.config.permissions;
    const allowedRoles = permissions[action] || [];

    // Check if all users allowed
    if (allowedRoles.includes('*')) {
      return true;
    }

    // Check if user has required role
    const hasRole = this.userPermissions.some(role => allowedRoles.includes(role));
    if (!hasRole) {
      return false;
    }

    // Check own-only restriction
    if (permissions.ownOnly && entry && this.currentUser) {
      return entry.user_id === this.currentUser.id || entry.created_by === this.currentUser.id;
    }

    return true;
  }

  /**
   * Filter data based on permissions
   */
  getPermissionFilteredData() {
    if (!this.config.permissions.enabled || !this.config.permissions.ownOnly) {
      return this.data;
    }

    return this.data.filter(entry => this.hasPermission('view', entry));
  }

  /**
   * State Persistence System
   */

  /**
   * Save current state to storage
   */
  saveState() {
    if (!this.config.state.persist) return;

    const state = {
      filters: this.filters,
      sortField: this.sortField,
      sortOrder: this.sortOrder,
      currentPage: this.currentPage,
      selectedRows: Array.from(this.selectedRows),
      timestamp: Date.now()
    };

    try {
      const storage = this.config.state.storage === 'sessionStorage' ?
        sessionStorage : localStorage;
      storage.setItem(this.config.state.key, JSON.stringify(state));
    } catch (error) {
      console.warn('Failed to save state:', error);
    }
  }

  /**
   * Load state from storage
   */
  loadState() {
    if (!this.config.state.persist) return;

    try {
      const storage = this.config.state.storage === 'sessionStorage' ?
        sessionStorage : localStorage;
      const stateJson = storage.getItem(this.config.state.key);

      if (!stateJson) return;

      const state = JSON.parse(stateJson);

      // Restore state
      this.filters = state.filters || {};
      this.sortField = state.sortField;
      this.sortOrder = state.sortOrder || 'asc';
      this.currentPage = state.currentPage || 1;
      this.selectedRows = new Set(state.selectedRows || []);

    } catch (error) {
      console.warn('Failed to load state:', error);
    }
  }

  /**
   * Clear saved state
   */
  clearState() {
    try {
      const storage = this.config.state.storage === 'sessionStorage' ?
        sessionStorage : localStorage;
      storage.removeItem(this.config.state.key);
    } catch (error) {
      console.warn('Failed to clear state:', error);
    }
  }

  /**
   * Rich Cell Types System
   */

  /**
   * Initialize built-in cell types
   */
  initializeCellTypes() {
    // Register built-in cell types
    this.registerCellType('text', this.createTextEditor.bind(this));
    this.registerCellType('textarea', this.createTextareaEditor.bind(this));
    this.registerCellType('number', this.createNumberEditor.bind(this));
    this.registerCellType('email', this.createEmailEditor.bind(this));
    this.registerCellType('date', this.createDateEditor.bind(this));
    this.registerCellType('datetime', this.createDateTimeEditor.bind(this));
    this.registerCellType('select', this.createSelectEditor.bind(this));
    this.registerCellType('multiselect', this.createMultiSelectEditor.bind(this));
    this.registerCellType('checkbox', this.createCheckboxEditor.bind(this));
    this.registerCellType('radio', this.createRadioEditor.bind(this));
    this.registerCellType('file', this.createFileEditor.bind(this));
    this.registerCellType('url', this.createUrlEditor.bind(this));
    this.registerCellType('color', this.createColorEditor.bind(this));
    this.registerCellType('range', this.createRangeEditor.bind(this));
  }

  /**
   * Register a custom cell type
   */
  registerCellType(type, editorFactory) {
    this.cellTypeRegistry.set(type, editorFactory);
  }

  /**
   * Create rich cell editor based on column type
   */
  async createRichCellEditor(column, currentValue, rowIndex) {
    const editorFactory = this.cellTypeRegistry.get(column.type);
    if (!editorFactory) {
      throw new Error(`Unknown cell type: ${column.type}`);
    }

    return await editorFactory(column, currentValue, rowIndex);
  }

  /**
   * Built-in Cell Type Editors
   */

  createTextEditor(column, currentValue) {
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue || '';
    input.className = 'tc-edit-input tc-text-input';

    if (column.maxLength) input.maxLength = column.maxLength;
    if (column.placeholder) input.placeholder = column.placeholder;

    return input;
  }

  createTextareaEditor(column, currentValue) {
    const textarea = document.createElement('textarea');
    textarea.value = currentValue || '';
    textarea.className = 'tc-edit-textarea';

    const config = this.config.cellTypes.textarea;
    textarea.rows = column.rows || config.rows;

    if (column.maxLength) textarea.maxLength = column.maxLength;
    if (column.placeholder) textarea.placeholder = column.placeholder;

    return textarea;
  }

  createNumberEditor(column, currentValue) {
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentValue || '';
    input.className = 'tc-edit-input tc-number-input';

    if (column.min !== undefined) input.min = column.min;
    if (column.max !== undefined) input.max = column.max;
    if (column.step !== undefined) input.step = column.step;
    if (column.placeholder) input.placeholder = column.placeholder;

    return input;
  }

  createEmailEditor(column, currentValue) {
    const input = document.createElement('input');
    input.type = 'email';
    input.value = currentValue || '';
    input.className = 'tc-edit-input tc-email-input';

    if (column.placeholder) input.placeholder = column.placeholder;

    return input;
  }

  createDateEditor(column, currentValue) {
    const input = document.createElement('input');
    input.type = 'date';
    input.className = 'tc-edit-input tc-date-input';

    // Format date value for input
    if (currentValue) {
      const date = new Date(currentValue);
      if (!isNaN(date.getTime())) {
        input.value = date.toISOString().split('T')[0];
      }
    }

    if (column.min) input.min = column.min;
    if (column.max) input.max = column.max;

    return input;
  }

  createDateTimeEditor(column, currentValue) {
    const input = document.createElement('input');
    input.type = 'datetime-local';
    input.className = 'tc-edit-input tc-datetime-input';

    // Format datetime value for input
    if (currentValue) {
      const date = new Date(currentValue);
      if (!isNaN(date.getTime())) {
        const offset = date.getTimezoneOffset();
        const localDate = new Date(date.getTime() - (offset * 60 * 1000));
        input.value = localDate.toISOString().slice(0, 16);
      }
    }

    return input;
  }

  createSelectEditor(column, currentValue) {
    const select = document.createElement('select');
    select.className = 'tc-edit-select';

    // Add default option
    if (column.placeholder) {
      const defaultOption = document.createElement('option');
      defaultOption.value = '';
      defaultOption.textContent = column.placeholder;
      defaultOption.disabled = true;
      select.appendChild(defaultOption);
    }

    // Add options
    const options = column.options || [];
    options.forEach(option => {
      const optionElement = document.createElement('option');

      if (typeof option === 'string') {
        optionElement.value = option;
        optionElement.textContent = option;
      } else {
        optionElement.value = option.value;
        optionElement.textContent = option.label || option.value;
      }

      if (optionElement.value === currentValue) {
        optionElement.selected = true;
      }

      select.appendChild(optionElement);
    });

    return select;
  }

  createMultiSelectEditor(column, currentValue) {
    const container = document.createElement('div');
    container.className = 'tc-multiselect-container';

    const selectedValues = Array.isArray(currentValue) ? currentValue :
      (currentValue ? currentValue.split(',') : []);

    const options = column.options || [];
    options.forEach(option => {
      const label = document.createElement('label');
      label.className = 'tc-multiselect-option';

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'tc-multiselect-checkbox';

      const text = document.createElement('span');

      if (typeof option === 'string') {
        checkbox.value = option;
        text.textContent = option;
        checkbox.checked = selectedValues.includes(option);
      } else {
        checkbox.value = option.value;
        text.textContent = option.label || option.value;
        checkbox.checked = selectedValues.includes(option.value);
      }

      label.appendChild(checkbox);
      label.appendChild(text);
      container.appendChild(label);
    });

    // Add method to get selected values
    container.getValue = function () {
      const checkboxes = this.querySelectorAll('input[type="checkbox"]:checked');
      return Array.from(checkboxes).map(cb => cb.value);
    };

    return container;
  }

  createCheckboxEditor(column, currentValue) {
    const container = document.createElement('div');
    container.className = 'tc-checkbox-container';

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'tc-edit-checkbox';
    checkbox.checked = this.isTruthy(currentValue);

    if (column.label) {
      const label = document.createElement('label');
      label.className = 'tc-checkbox-label';

      const text = document.createElement('span');
      text.textContent = column.label;

      label.appendChild(checkbox);
      label.appendChild(text);
      container.appendChild(label);
    } else {
      container.appendChild(checkbox);
    }

    // Add method to get value
    container.getValue = function () {
      return this.querySelector('input[type="checkbox"]').checked;
    };

    return container;
  }

  createRadioEditor(column, currentValue) {
    const container = document.createElement('div');
    container.className = 'tc-radio-container';

    const fieldName = `radio_${Date.now()}_${Math.random()}`;
    const options = column.options || [];

    options.forEach(option => {
      const label = document.createElement('label');
      label.className = 'tc-radio-option';

      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = fieldName;
      radio.className = 'tc-edit-radio';

      const text = document.createElement('span');

      if (typeof option === 'string') {
        radio.value = option;
        text.textContent = option;
        radio.checked = option === currentValue;
      } else {
        radio.value = option.value;
        text.textContent = option.label || option.value;
        radio.checked = option.value === currentValue;
      }

      label.appendChild(radio);
      label.appendChild(text);
      container.appendChild(label);
    });

    // Add method to get selected value
    container.getValue = function () {
      const selected = this.querySelector('input[type="radio"]:checked');
      return selected ? selected.value : '';
    };

    return container;
  }

  createFileEditor(column, currentValue) {
    const container = document.createElement('div');
    container.className = 'tc-file-container';

    const input = document.createElement('input');
    input.type = 'file';
    input.className = 'tc-edit-file';

    if (column.accept) input.accept = column.accept;
    if (column.multiple) input.multiple = column.multiple;

    // Show current file if exists
    if (currentValue) {
      const preview = document.createElement('div');
      preview.className = 'tc-file-preview';
      preview.textContent = `Current: ${currentValue}`;
      container.appendChild(preview);
    }

    container.appendChild(input);

    // Add method to get value
    container.getValue = function () {
      const fileInput = this.querySelector('input[type="file"]');
      return fileInput.files.length > 0 ? fileInput.files[0].name : currentValue;
    };

    return container;
  }

  createUrlEditor(column, currentValue) {
    const input = document.createElement('input');
    input.type = 'url';
    input.value = currentValue || '';
    input.className = 'tc-edit-input tc-url-input';
    input.placeholder = column.placeholder || 'https://example.com';

    return input;
  }

  createColorEditor(column, currentValue) {
    const container = document.createElement('div');
    container.className = 'tc-color-container';

    const input = document.createElement('input');
    input.type = 'color';
    input.value = currentValue || '#000000';
    input.className = 'tc-edit-color';

    const textInput = document.createElement('input');
    textInput.type = 'text';
    textInput.value = currentValue || '#000000';
    textInput.className = 'tc-color-text';
    textInput.placeholder = '#000000';

    // Sync color picker and text input
    input.addEventListener('change', () => {
      textInput.value = input.value;
    });

    textInput.addEventListener('change', () => {
      if (/^#[0-9A-F]{6}$/i.test(textInput.value)) {
        input.value = textInput.value;
      }
    });

    container.appendChild(input);
    container.appendChild(textInput);

    // Add method to get value
    container.getValue = function () {
      return this.querySelector('.tc-color-text').value;
    };

    return container;
  }

  createRangeEditor(column, currentValue) {
    const container = document.createElement('div');
    container.className = 'tc-range-container';

    const range = document.createElement('input');
    range.type = 'range';
    range.value = currentValue || column.min || 0;
    range.className = 'tc-edit-range';

    if (column.min !== undefined) range.min = column.min;
    if (column.max !== undefined) range.max = column.max;
    if (column.step !== undefined) range.step = column.step;

    const display = document.createElement('span');
    display.className = 'tc-range-display';
    display.textContent = range.value;

    range.addEventListener('input', () => {
      display.textContent = range.value;
    });

    container.appendChild(range);
    container.appendChild(display);

    // Add method to get value
    container.getValue = function () {
      return this.querySelector('input[type="range"]').value;
    };

    return container;
  }

  /**
   * Helper method to determine if a value is truthy for checkboxes
   */
  isTruthy(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'string') {
      return ['true', '1', 'yes', 'on'].includes(value.toLowerCase());
    }
    if (typeof value === 'number') return value !== 0;
    return false;
  }

  /**
   * Data Validation System
   */

  /**
   * Initialize validation rules for columns
   */
  initializeValidation() {
    if (!this.config.validation.enabled) return;

    this.config.columns.forEach(column => {
      if (column.validation) {
        this.validationRules.set(column.field, column.validation);
      }
    });
  }

  /**
   * Validate a single field value
   */
  validateField(field, value, rowData = {}) {
    if (!this.config.validation.enabled) return { isValid: true };

    const rules = this.validationRules.get(field) || this.config.validation.rules[field];
    if (!rules) return { isValid: true };

    const errors = [];

    // Required validation
    if (rules.required && (value === null || value === undefined || value === '')) {
      errors.push(this.getValidationMessage('required', rules));
    }

    // Skip other validations if empty and not required
    if (!rules.required && (value === null || value === undefined || value === '')) {
      return { isValid: true };
    }

    // Email validation
    if (rules.email || rules.type === 'email') {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(value)) {
        errors.push(this.getValidationMessage('email', rules));
      }
    }

    // Min/Max length validation
    if (rules.minLength && value.length < rules.minLength) {
      errors.push(this.getValidationMessage('minLength', rules));
    }
    if (rules.maxLength && value.length > rules.maxLength) {
      errors.push(this.getValidationMessage('maxLength', rules));
    }

    // Min/Max value validation (for numbers)
    if (rules.min !== undefined) {
      const numValue = parseFloat(value);
      if (!isNaN(numValue) && numValue < rules.min) {
        errors.push(this.getValidationMessage('min', rules));
      }
    }
    if (rules.max !== undefined) {
      const numValue = parseFloat(value);
      if (!isNaN(numValue) && numValue > rules.max) {
        errors.push(this.getValidationMessage('max', rules));
      }
    }

    // Pattern validation
    if (rules.pattern) {
      const regex = new RegExp(rules.pattern);
      if (!regex.test(value)) {
        errors.push(this.getValidationMessage('pattern', rules));
      }
    }

    // Custom validation function
    if (rules.custom && typeof rules.custom === 'function') {
      try {
        const result = rules.custom(value, rowData, field);
        if (result !== true) {
          errors.push(typeof result === 'string' ? result : this.getValidationMessage('custom', rules));
        }
      } catch (error) {
        errors.push(this.getValidationMessage('custom', rules));
      }
    }

    return {
      isValid: errors.length === 0,
      errors: errors
    };
  }

  /**
   * Get validation message with parameter substitution
   */
  getValidationMessage(type, rules) {
    let message = rules.message || this.config.validation.messages[type];

    // Substitute parameters
    if (rules.minLength) message = message.replace('{min}', rules.minLength);
    if (rules.maxLength) message = message.replace('{max}', rules.maxLength);
    if (rules.min !== undefined) message = message.replace('{min}', rules.min);
    if (rules.max !== undefined) message = message.replace('{max}', rules.max);

    return message;
  }

  /**
   * Validate entire row
   */
  validateRow(rowData, rowIndex) {
    if (!this.config.validation.enabled) return { isValid: true };

    const errors = {};
    let isValid = true;

    this.config.columns.forEach(column => {
      const validation = this.validateField(column.field, rowData[column.field], rowData);
      if (!validation.isValid) {
        errors[column.field] = validation.errors;
        isValid = false;
      }
    });

    return { isValid, errors };
  }

  /**
   * Show validation error for a cell
   */
  showValidationError(element, errors) {
    if (!this.config.validation.showErrors || !errors || errors.length === 0) return;

    // Remove existing error
    this.clearValidationError(element);

    // Add error class
    element.classList.add('tc-validation-error');

    // Create error tooltip
    const errorTooltip = document.createElement('div');
    errorTooltip.className = 'tc-validation-tooltip';
    errorTooltip.textContent = errors[0]; // Show first error

    // Position tooltip
    const rect = element.getBoundingClientRect();
    errorTooltip.style.position = 'absolute';
    errorTooltip.style.top = (rect.bottom + window.scrollY + 5) + 'px';
    errorTooltip.style.left = (rect.left + window.scrollX) + 'px';
    errorTooltip.style.zIndex = '1000';

    document.body.appendChild(errorTooltip);

    // Store reference for cleanup
    element._validationTooltip = errorTooltip;

    // Auto-hide after 5 seconds
    setTimeout(() => this.clearValidationError(element), 5000);
  }

  /**
   * Clear validation error for a cell
   */
  clearValidationError(element) {
    element.classList.remove('tc-validation-error');

    if (element._validationTooltip) {
      document.body.removeChild(element._validationTooltip);
      delete element._validationTooltip;
    }
  }

  /**
   * Set validation error state for a cell
   */
  setValidationError(rowIndex, field, errors) {
    const key = `${rowIndex}_${field}`;
    if (errors && errors.length > 0) {
      this.validationErrors.set(key, errors);
    } else {
      this.validationErrors.delete(key);
    }
  }

  /**
   * Get validation errors for a cell
   */
  getValidationErrors(rowIndex, field) {
    const key = `${rowIndex}_${field}`;
    return this.validationErrors.get(key) || [];
  }

  /**
   * Clear all validation errors
   */
  clearAllValidationErrors() {
    this.validationErrors.clear();
    // Remove all error classes and tooltips
    const errorElements = this.container.querySelectorAll('.tc-validation-error');
    errorElements.forEach(element => this.clearValidationError(element));
  }

  /**
   * Show validation errors in a form (for Add New modal)
   */
  showFormValidationErrors(form, fieldErrors) {
    // Clear existing errors
    const existingErrors = form.querySelectorAll('.tc-validation-message');
    existingErrors.forEach(error => error.remove());

    const errorFields = form.querySelectorAll('.tc-field-error');
    errorFields.forEach(field => field.classList.remove('tc-field-error'));

    // Show new errors
    Object.keys(fieldErrors).forEach(fieldName => {
      const field = form.querySelector(`[name="${fieldName}"]`);
      if (field) {
        // Add error class to field
        field.classList.add('tc-field-error');

        // Create error message
        const errorMessage = document.createElement('span');
        errorMessage.className = 'tc-validation-message';
        errorMessage.textContent = fieldErrors[fieldName][0]; // Show first error

        // Insert after the field
        field.parentNode.insertBefore(errorMessage, field.nextSibling);
      }
    });
  }

  /**
   * Destroy the table instance
   */
  destroy() {
    // Save final state
    this.saveState();

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
    this.lookupCache.clear();

    // Cleanup dropdowns appended to body
    if (this.dropdowns) {
      this.dropdowns.forEach(dropdown => dropdown.remove());
      this.dropdowns = [];
    }
  }
}

// Export for different module systems
if (typeof module !== 'undefined' && module.exports) {
  module.exports = TableCrafter;
}

if (typeof define === 'function' && define.amd) {
  define([], function () {
    return TableCrafter;
  });
}

if (typeof window !== 'undefined') {
  window.TableCrafter = TableCrafter;
}