// TableCrafter type definitions — #33

export type CellType =
  | 'text' | 'textarea' | 'number' | 'email' | 'date' | 'datetime'
  | 'select' | 'multiselect' | 'checkbox' | 'radio' | 'file'
  | 'url' | 'color' | 'range' | 'badge' | 'progress' | 'link' | 'sparkline'
  | (string & {});

export type SortDirection = 'asc' | 'desc';
export type ExportFormat = 'csv' | 'json' | 'xlsx' | 'pdf' | (string & {});
export type StorageType = 'localStorage' | 'sessionStorage' | (string & {});
export type FilterOp = 'gt' | 'lt' | 'gte' | 'lte' | 'eq' | 'neq' | 'between' | 'contains' | 'regex' | 'empty';
export type ColumnPinSide = 'left' | 'right';
export type CFScope = 'cell' | 'row';
export type CFKind = 'dataBar' | 'colorScale' | 'icon';

// ── Column ───────────────────────────────────────────────────────────────────

export interface LookupConfig {
  url: string;
  valueField?: string;
  labelField?: string;
  params?: Record<string, string>;
}

export interface SparklineConfig {
  type?: 'line' | 'bar';
  width?: number;
  height?: number;
  color?: string;
  fillColor?: string;
}

export interface TableCrafterColumn {
  field: string;
  label?: string;
  type?: CellType;
  sortable?: boolean;
  filterable?: boolean;
  editable?: boolean;
  exportable?: boolean;
  hidden?: boolean;
  width?: string | number;
  minWidth?: string | number;
  maxWidth?: string | number;
  pinned?: ColumnPinSide;
  formula?: string;
  lookup?: LookupConfig;
  cellType?: CellType;
  sparkline?: SparklineConfig;
  options?: string[] | Array<{ value: string; label: string }>;
  format?: string;
  align?: 'left' | 'center' | 'right';
  wrap?: boolean;
}

// ── Sorting ──────────────────────────────────────────────────────────────────

export interface SortKey {
  field: string;
  direction: SortDirection;
}

// ── Filtering ────────────────────────────────────────────────────────────────

export interface FilterValue {
  from?: string | number;
  to?: string | number;
}

// ── Conditional formatting ───────────────────────────────────────────────────

export interface CFDeclarativeCondition {
  op: FilterOp;
  value?: unknown;
}

export type CFPredicate =
  | ((value: unknown, row: Record<string, unknown>, ctx: TableCrafter) => boolean)
  | CFDeclarativeCondition;

export interface ConditionalFormattingRule {
  id: string;
  field: string;
  when: CFPredicate;
  style?: Partial<CSSStyleDeclaration> & Record<string, string>;
  className?: string | string[];
  priority?: number;
  scope?: CFScope;
  kind?: CFKind;
  icon?: string;
  color?: string;
  min?: number;
  max?: number;
  minColor?: string;
  maxColor?: string;
  midColor?: string;
  ariaLabel?: (value: unknown, row: Record<string, unknown>) => string;
}

// ── Search / query AST ───────────────────────────────────────────────────────

export type QueryFieldOp = 'eq' | 'gt' | 'gte' | 'lt' | 'lte' | 'regex';

export interface QueryAndNode { type: 'and'; children: QueryNode[] }
export interface QueryOrNode  { type: 'or';  children: QueryNode[] }
export interface QueryNotNode { type: 'not'; child: QueryNode }
export interface QueryTermNode   { type: 'term';   value: string }
export interface QueryPhraseNode { type: 'phrase'; value: string }
export interface QueryFieldNode  { type: 'field';  field: string; op: QueryFieldOp; value: string }
export type QueryNode = QueryAndNode | QueryOrNode | QueryNotNode | QueryTermNode | QueryPhraseNode | QueryFieldNode;

// ── Presets ──────────────────────────────────────────────────────────────────

export interface SearchPreset {
  id: string;
  label: string;
  query: string;
}

// ── Plugins ──────────────────────────────────────────────────────────────────

export interface TableCrafterPlugin {
  name?: string;
  install(table: TableCrafter, options?: Record<string, unknown>): void;
}

// ── Context menu ─────────────────────────────────────────────────────────────

export interface ContextMenuItem {
  label: string;
  action: (row: Record<string, unknown>, field: string, table: TableCrafter) => void;
  separator?: boolean;
  disabled?: boolean | ((row: Record<string, unknown>) => boolean);
}

// ── Config ───────────────────────────────────────────────────────────────────

export interface TableCrafterConfig {
  // Data
  data?: Record<string, unknown>[] | string;
  columns?: TableCrafterColumn[];

  // Behaviour
  editable?: boolean;
  sortable?: boolean;
  filterable?: boolean;
  globalSearch?: boolean;
  globalSearchPlaceholder?: string;
  pagination?: boolean;
  pageSize?: number;
  currentPage?: number;

  // Export
  exportable?: boolean;
  exportFiltered?: boolean;
  exportFilename?: string;
  export?: {
    formats?: ExportFormat[];
  };

  // Search
  search?: {
    suggestions?: boolean;
    builder?: boolean;
    presets?: SearchPreset[];
  };

  // Theming
  theme?: string;
  themeVariables?: Record<string, string>;

  // Filters
  filters?: {
    advanced?: boolean;
    autoDetect?: boolean;
    types?: Record<string, string>;
    showClearAll?: boolean;
  };

  // Bulk operations
  bulk?: {
    enabled?: boolean;
    operations?: Array<'delete' | 'export' | 'edit'>;
    showProgress?: boolean;
  };

  // Add-new modal
  addNew?: {
    enabled?: boolean;
    modal?: boolean;
    fields?: TableCrafterColumn[];
    validation?: Record<string, unknown>;
  };

  // Validation
  validation?: {
    enabled?: boolean;
    showErrors?: boolean;
    validateOnEdit?: boolean;
    validateOnSubmit?: boolean;
    rules?: Record<string, unknown[]>;
    messages?: Record<string, string>;
  };

  // Responsive
  responsive?: {
    enabled?: boolean;
    breakpoints?: {
      mobile?: { width: number; layout: string };
      tablet?: { width: number; layout: string };
      desktop?: { width: number; layout: string };
    };
    fieldVisibility?: Record<string, string[]>;
  };

  // API integration
  api?: {
    baseUrl?: string;
    endpoints?: {
      data?: string;
      create?: string;
      update?: string;
      delete?: string;
      lookup?: string;
    };
    headers?: Record<string, string>;
    authentication?: Record<string, unknown> | null;
  };

  // i18n
  i18n?: {
    locale?: string | null;
    fallbackLocale?: string;
    messages?: Record<string, Record<string, string>>;
    formats?: Record<string, unknown>;
  };

  // Conditional formatting
  conditionalFormatting?: {
    enabled?: boolean;
    rules?: ConditionalFormattingRule[];
  };

  // Context menu
  contextMenu?: {
    enabled?: boolean;
    items?: ContextMenuItem[];
  };

  // Permissions
  permissions?: {
    enabled?: boolean;
    view?: string[];
    edit?: string[];
    delete?: string[];
    create?: string[];
    ownOnly?: boolean;
  };

  // State persistence
  state?: {
    persist?: boolean;
    storage?: StorageType;
    key?: string;
  };

  // Plugins
  plugins?: Array<TableCrafterPlugin | [TableCrafterPlugin, Record<string, unknown>]>;

  // Callbacks
  onExport?: (event: { format: ExportFormat; data: Record<string, unknown>[] }) => void;
  onEdit?: (event: { row: Record<string, unknown>; field: string; value: unknown }) => void;
  onDelete?: (event: { row: Record<string, unknown> }) => void;
  onCreate?: (event: { row: Record<string, unknown> }) => void;
  onSort?: (event: { field: string; direction: SortDirection }) => void;
  onFilter?: (event: { filters: Record<string, unknown> }) => void;
  onPageChange?: (event: { page: number; totalPages: number }) => void;
  onRowSelect?: (event: { selected: number[]; row?: Record<string, unknown> }) => void;

  [key: string]: unknown;
}

// ── Validation errors ────────────────────────────────────────────────────────

export interface ValidationError {
  field: string;
  message: string;
}

// ── Aggregation ──────────────────────────────────────────────────────────────

export type AggregateFunc = 'sum' | 'avg' | 'min' | 'max' | 'count' | 'distinct';

export interface AggregateResult {
  field: string;
  func: AggregateFunc;
  value: number;
}

// ── Stats ────────────────────────────────────────────────────────────────────

export interface TableStats {
  totalRows: number;
  filteredRows: number;
  selectedRows: number;
  currentPage: number;
  totalPages: number;
  lastRenderMs: number;
}

// ── Main class ───────────────────────────────────────────────────────────────

export declare class TableCrafter {
  config: Required<TableCrafterConfig>;
  data: Record<string, unknown>[];
  currentPage: number;
  sortField: string | null;
  sortOrder: SortDirection;
  sortKeys: SortKey[];
  filters: Record<string, unknown>;
  searchTerm: string;
  isLoading: boolean;
  selectedRows: Set<number>;

  constructor(container: string | HTMLElement, config?: TableCrafterConfig);

  // Lifecycle
  render(): void;
  destroy(): void;

  // Data
  getData(): Record<string, unknown>[];
  setData(data: Record<string, unknown>[]): void;
  loadData(): Promise<void>;
  processData(data: Record<string, unknown>[]): Record<string, unknown>[];
  autoDiscoverColumns(): void;

  // Filtering & search
  getFilteredData(): Record<string, unknown>[];
  setFilter(field: string, value: unknown): void;
  clearFilters(): void;
  parseQuery(input: string): QueryAndNode;
  setQuery(query: string): void;
  savePreset(label: string): SearchPreset;
  removePreset(id: string): void;

  // Sorting
  sort(field: string, options?: { append?: boolean; direction?: SortDirection }): void;
  multiSort(keys: SortKey[]): void;

  // Pagination
  getPaginatedData(options?: { allPages?: boolean }): Record<string, unknown>[];
  getTotalPages(): number;
  goToPage(page: number): void;
  nextPage(): void;
  prevPage(): void;

  // Selection
  toggleRowSelection(rowIndex: number, selected?: boolean): void;
  selectAllRows(): void;
  deselectAllRows(): void;

  // Export
  exportToCSV(): string;
  exportToJSON(): string;
  exportData(format: ExportFormat): Promise<Blob | string>;
  downloadExport(format: ExportFormat, filename?: string): Promise<void>;
  downloadCSV(): void;
  getExportableData(): Record<string, unknown>[];
  getExportableColumns(): TableCrafterColumn[];

  // Editing
  startEdit(event: Event, rowIndex: number, field: string): Promise<void>;
  saveEdit(rowIndex: number, field: string, value: unknown): Promise<void>;
  cancelEdit(): void;

  // CRUD
  addRow(row: Record<string, unknown>): Promise<void>;
  updateRow(rowIndex: number, updates: Record<string, unknown>): Promise<void>;
  removeRow(rowIndex: number): Promise<void>;

  // Conditional formatting
  evaluateRule(rule: ConditionalFormattingRule, value: unknown, row?: Record<string, unknown>): boolean;
  getMatchingRules(field: string, value: unknown, row: Record<string, unknown>): ConditionalFormattingRule[];
  addRule(rule: ConditionalFormattingRule): ConditionalFormattingRule;
  removeRule(id: string): boolean;
  setRules(rules: ConditionalFormattingRule[]): void;
  applyConditionalFormatting(td: HTMLTableCellElement, field: string, value: unknown, row: Record<string, unknown>): void;

  // Columns
  addColumn(column: TableCrafterColumn, index?: number): void;
  removeColumn(field: string): void;
  updateColumn(field: string, updates: Partial<TableCrafterColumn>): void;
  getColumn(field: string): TableCrafterColumn | undefined;
  getVisibleColumns(): TableCrafterColumn[];
  setColumnVisibility(field: string, visible: boolean): void;
  setColumnOrder(fields: string[]): void;
  pinColumn(field: string, side: ColumnPinSide | null): void;
  getPinnedColumns(): { left: TableCrafterColumn[]; right: TableCrafterColumn[] };

  // Theming
  getTheme(): string;
  setTheme(theme: string): void;

  // i18n
  setLocale(locale: string): void;
  addMessages(locale: string, messages: Record<string, string>): void;
  t(key: string, vars?: Record<string, unknown>): string;

  // Validation
  validateField(field: string, value: unknown, rowData?: Record<string, unknown>): string | null;
  validateRow(rowData: Record<string, unknown>, rowIndex?: number): ValidationError[];
  validate(rowData: Record<string, unknown>): Promise<ValidationError[]>;
  getErrors(rowIndex?: number): ValidationError[];
  clearErrors(rowIndex?: number, field?: string): void;
  clearAllValidationErrors(): void;

  // Aggregation
  aggregate(field: string, func: AggregateFunc): number;
  getAggregates(fields?: string[]): AggregateResult[];

  // Formulas
  evaluateFormula(formula: string, row: Record<string, unknown>): unknown;

  // Cell types
  registerCellType(type: string, handler: Record<string, unknown>): void;

  // State persistence
  saveState(): void;
  loadState(): void;
  clearState(): void;

  // Plugins
  use(plugin: TableCrafterPlugin, options?: Record<string, unknown>): this;
  unuse(name: string): void;
  getPlugins(): TableCrafterPlugin[];

  // Permissions
  setCurrentUser(user: Record<string, unknown>): void;
  hasPermission(action: string, row?: Record<string, unknown>): boolean;
  getPermissionFilteredData(): Record<string, unknown>[];

  // Context menu
  openContextMenu(event: MouseEvent, row: Record<string, unknown>, field: string): void;
  closeContextMenu(): void;

  // Cell selection
  selectRange(anchor: { row: number; field: string }, focus: { row: number; field: string }): void;
  getSelection(): Record<string, unknown> | null;
  clearSelection(): void;
  copySelectionAsTSV(): string;

  // Virtual scroll
  enableVirtualScroll(): void;
  disableVirtualScroll(): void;
  isVirtualScrolling(): boolean;

  // Memory / caching
  clearCaches(): void;
  getMemoryFootprint(): Record<string, number>;

  // Benchmarking
  bench(name: string, fn: () => void, options?: Record<string, unknown>): Promise<Record<string, number>>;
  benchRender(options?: Record<string, unknown>): Promise<Record<string, number>>;
  benchFilter(query: string, options?: Record<string, unknown>): Promise<Record<string, number>>;

  // Stats
  getStats(): TableStats;

  // CSV import
  parseCSV(text: string, options?: { delimiter?: string; header?: boolean }): { rows: Record<string, unknown>[]; errors: string[] };
  importCSV(text: string, options?: Record<string, unknown>): void;

  // Utilities
  formatValue(value: unknown, type?: string): string;
  detectDataType(value: unknown): string;
  isMobile(): boolean;
  getCurrentBreakpoint(): 'mobile' | 'tablet' | 'desktop';
  getVisibleFields(breakpoint: string): string[];
  getHiddenFields(breakpoint: string): string[];
  renderSparkline(data: number[], config?: SparklineConfig): SVGSVGElement | null;
}

export default TableCrafter;
