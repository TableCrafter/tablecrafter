// TableCrafter — public TypeScript declarations.
//
// This file is hand-curated. The runtime is plain ES classes with rich
// defaults; auto-generation from JSDoc tends to produce a noisy, churn-prone
// surface so we keep this small and editable instead.

declare namespace TableCrafterTypes {
  interface Column {
    field: string;
    label?: string;
    sortable?: boolean;
    editable?: boolean;
    filterable?: boolean;
    exportable?: boolean;
    hidden?: boolean;
    pinned?: 'left' | 'right' | false;
    type?: string;
    cellType?: string;
    aggregate?: 'sum' | 'avg' | 'count' | 'min' | 'max' | ((values: any[], rows: any[]) => any);
    formula?: string;
    badge?: { statusFor?(value: any, row: any): string };
    progress?: { max?: number };
    link?: { hrefFor?(value: any, row: any): string; labelFrom?: string };
    sparkline?: { width?: number; height?: number; stroke?: string };
    bars?: { width?: number; height?: number; gap?: number; fill?: string };
    heatmap?: { width?: number; height?: number; minColor?: string; maxColor?: string };
    [key: string]: unknown;
  }

  interface I18nConfig {
    locale?: string | null;
    fallbackLocale?: string;
    messages?: Record<string, Record<string, string | { one?: string; other?: string; [key: string]: string | undefined }>>;
    formats?: { number?: Intl.NumberFormatOptions; date?: Intl.DateTimeFormatOptions };
  }

  interface PermissionsConfig {
    enabled?: boolean;
    view?: string[];
    edit?: string[];
    delete?: string[];
    create?: string[];
    ownOnly?: boolean;
  }

  interface ConditionalFormattingRule {
    id?: string;
    field: string;
    when: ((value: any, row: any, ctx: { table: TableCrafter; field: string }) => boolean) |
          { op: string; value?: any };
    style?: Record<string, string>;
    className?: string | string[];
    priority?: number;
    scope?: 'cell' | 'row';
    kind?: 'icon' | 'dataBar' | 'colorScale';
    icon?: string;
    min?: number;
    mid?: number;
    max?: number;
    minColor?: string;
    midColor?: string;
    maxColor?: string;
    ariaLabel?(value: any, row: any): string;
  }

  interface ContextMenuItem {
    id: string;
    label: string;
    icon?: string;
    onClick(payload: { context: any }): void;
    visible?(payload: { context: any }): boolean;
    disabled?(payload: { context: any }): boolean;
    scope?: 'row' | 'cell' | 'header' | 'all';
  }

  interface Plugin {
    name: string;
    version?: string;
    install?(table: TableCrafter, options?: any): void;
    uninstall?(table: TableCrafter): void;
    hooks?: {
      beforeRender?(payload: { table: TableCrafter }): boolean | void;
      afterRender?(payload: { table: TableCrafter }): void;
      beforeLoad?(payload: { source: string }): boolean | void;
      afterLoad?(payload: { data: any[] }): void;
      beforeEdit?(payload: { rowIndex: number; field: string; value: any }): boolean | void;
      afterEdit?(payload: { rowIndex: number; field: string; oldValue: any; newValue: any }): void;
      beforeSort?(payload: { field: string; order: 'asc' | 'desc' }): boolean | void;
      afterSort?(payload: { field: string; order: 'asc' | 'desc' }): void;
      destroy?(payload: { table: TableCrafter }): void;
      [hook: string]: ((payload: any, table: TableCrafter) => boolean | void) | undefined;
    };
  }

  interface SearchPreset {
    id?: string;
    label: string;
    query: string;
  }

  interface TableConfig {
    data?: any[] | string;
    columns?: Column[];
    pageSize?: number;
    sortable?: boolean;
    filterable?: boolean;
    globalSearch?: boolean;
    pagination?: boolean;
    editable?: boolean;
    exportable?: boolean;
    exportFiltered?: boolean;
    exportFilename?: string;
    responsive?: { enabled?: boolean; breakpoints?: Record<string, { width: number; layout?: string }> };
    bulk?: { enabled?: boolean; operations?: string[]; showProgress?: boolean };
    addNew?: { enabled?: boolean; modal?: boolean; fields?: any[] };
    validation?: {
      enabled?: boolean;
      showErrors?: boolean;
      validateOnEdit?: boolean;
      validateOnSubmit?: boolean;
      rules?: Record<string, any>;
      messages?: Record<string, string>;
      rowRules?: Array<(payload: { row: any; rowIndex: number }) => Array<{ field: string; message: string }>>;
    };
    cellTypes?: Record<string, any>;
    api?: { baseUrl?: string; endpoints?: Record<string, string>; headers?: Record<string, string>; authentication?: any };
    i18n?: I18nConfig;
    permissions?: PermissionsConfig;
    state?: { persist?: boolean; storage?: 'localStorage' | 'sessionStorage'; key?: string };
    theme?: string;
    themeVariables?: Record<string, string>;
    conditionalFormatting?: { enabled?: boolean; rules?: ConditionalFormattingRule[] };
    contextMenu?: { enabled?: boolean; items?: Array<ContextMenuItem | 'separator'> };
    plugins?: Array<Plugin | [Plugin, any]>;
    search?: { presets?: SearchPreset[]; suggestions?: boolean; builder?: boolean };
    export?: { formats?: Array<'csv' | 'json' | 'xlsx' | 'pdf'> };
    onAdd?(payload: { row: any; index: number }): void;
    onUpdate?(payload: { row: any; index: number; previous: any }): void;
    onDelete?(payload: { row: any; index: number }): void;
    onEdit?(payload: { row: number; field: string; oldValue: any; newValue: any }): void;
    onExport?(payload: { format: string; data: any[]; csvData?: string; jsonData?: string }): void;
    [key: string]: unknown;
  }

  interface QueryNode {
    type: 'and' | 'or' | 'not' | 'term' | 'phrase' | 'field';
    children?: QueryNode[];
    child?: QueryNode;
    value?: string;
    field?: string;
    op?: 'eq' | 'eq_strict' | 'gt' | 'lt' | 'gte' | 'lte' | 'regex';
    flags?: string;
  }

  interface BenchResult {
    label: string;
    runs: number;
    min: number;
    max: number;
    mean: number;
    median: number;
    p95: number;
    totalMs: number;
  }

  interface ImportResult {
    rows: any[];
    errors: Array<{ line?: number; index?: number; message: string }>;
  }

  interface CellSelection {
    startRow: number;
    endRow: number;
    startCol: string;
    endCol: string;
    anchor: { row: number; field: string };
    focus: { row: number; field: string };
  }

  interface BrowserSupport {
    intl: boolean;
    intlPluralRules: boolean;
    resizeObserver: boolean;
    performanceNow: boolean;
    svgInHtml: boolean;
    abortController: boolean;
    cssCustomProperties: boolean;
    requiredFeaturesAvailable: boolean;
  }
}

declare class TableCrafter {
  constructor(container: string | HTMLElement, config?: TableCrafterTypes.TableConfig);

  // Core
  data: any[];
  config: TableCrafterTypes.TableConfig;
  render(): void;
  loadData(): Promise<any[]>;
  destroy(): void;

  // Data access
  getData(): any[];
  getFilteredData(): any[];

  // Sort / search / filters
  sort(field: string): void;
  setQuery(query: string): void;
  parseQuery(input: string): TableCrafterTypes.QueryNode;
  evaluateQuery(ast: TableCrafterTypes.QueryNode, row: any): boolean;
  getPresets(): TableCrafterTypes.SearchPreset[];
  savePreset(label: string, query?: string): TableCrafterTypes.SearchPreset;
  savePreset(record: TableCrafterTypes.SearchPreset): TableCrafterTypes.SearchPreset;
  removePreset(id: string): boolean;
  applyPreset(id: string): boolean;

  // Row CRUD
  addRow(rowData: any): Promise<any>;
  updateRow(index: number, rowData: any): Promise<any>;
  removeRow(index: number, options?: { confirm?: boolean }): Promise<boolean>;

  // Column management
  addColumn(column: TableCrafterTypes.Column, options?: { before?: string }): TableCrafterTypes.Column;
  removeColumn(field: string): boolean;
  updateColumn(field: string, patch: Partial<TableCrafterTypes.Column>): TableCrafterTypes.Column;
  getColumn(field: string): TableCrafterTypes.Column | null;
  setColumnVisibility(field: string, visible: boolean): void;
  setColumnOrder(fields: string[]): void;
  getVisibleColumns(): TableCrafterTypes.Column[];
  pinColumn(field: string, side: 'left' | 'right' | false): void;
  getPinnedColumns(): { left: TableCrafterTypes.Column[]; right: TableCrafterTypes.Column[] };

  // Validation
  validate(): Promise<{ isValid: boolean; errors: Record<number, Record<string, string[]>> }>;
  validateField(field: string, value: any, rowData?: any): { isValid: boolean; errors?: string[] };
  getErrors(rowIndex?: number): any;
  clearErrors(rowIndex?: number, field?: string): void;

  // Aggregation
  getAggregates(rows?: any[]): Record<string, any>;
  aggregate(field: string, fn?: any, rows?: any[]): any;
  groupBy(field: string, rows?: any[]): Map<any, any[]>;
  getGroupAggregates(field: string, rows?: any[]): Map<any, Record<string, any>>;

  // Formulas
  evaluateFormula(formula: string, row: any): number | string | null;

  // Conditional formatting
  evaluateRule(rule: TableCrafterTypes.ConditionalFormattingRule, value: any, row?: any): boolean;
  getMatchingRules(field: string, value: any, row: any): TableCrafterTypes.ConditionalFormattingRule[];
  addRule(rule: TableCrafterTypes.ConditionalFormattingRule): TableCrafterTypes.ConditionalFormattingRule;
  removeRule(id: string): boolean;
  setRules(rules: TableCrafterTypes.ConditionalFormattingRule[]): void;

  // Context menu
  openContextMenu(scope: 'row' | 'cell' | 'header', context: any): void;
  closeContextMenu(): void;

  // Cell selection
  selectRange(anchor: { row: number; field: string }, focus: { row: number; field: string }): void;
  getSelection(): TableCrafterTypes.CellSelection | null;
  clearSelection(): void;
  copySelectionAsTSV(): string;

  // Plugins
  use(plugin: TableCrafterTypes.Plugin, options?: any): { plugin: TableCrafterTypes.Plugin; options?: any };
  unuse(name: string): boolean;
  getPlugins(): Array<{ name: string; version?: string; options?: any }>;

  // i18n
  t(key: string, vars?: Record<string, any>): string;
  setLocale(locale: string): void;
  getLocale?(): string;
  addMessages(locale: string, messages: Record<string, any>): void;
  isRTL(): boolean;
  formatNumber(value: any, options?: Intl.NumberFormatOptions): string;
  formatDate(value: any, options?: Intl.DateTimeFormatOptions): string;

  // Theming
  getTheme(): string;
  setTheme(name: string): void;

  // Permissions
  setCurrentUser(user: { id?: any; roles?: string[]; permissions?: string[]; [key: string]: any }): void;
  hasPermission(action: 'view' | 'edit' | 'delete' | 'create', entry?: any): boolean;

  // Export
  exportData(format: 'csv' | 'json' | 'xlsx' | 'pdf'): Promise<string | Blob>;
  exportToCSV(): string;
  exportToJSON(): string;
  downloadCSV(): void;
  downloadExport(format: string, filename?: string): Promise<void>;

  // Import
  parseCSV(text: string, options?: { delimiter?: string; header?: boolean }): TableCrafterTypes.ImportResult;
  importCSV(text: string, options?: { delimiter?: string; header?: boolean; append?: boolean }): TableCrafterTypes.ImportResult;
  parseJSON(input: string | any[] | { data: any[] }): TableCrafterTypes.ImportResult;
  importJSON(input: string | any[] | { data: any[] }, options?: { append?: boolean }): TableCrafterTypes.ImportResult;

  // Visualisation
  renderSparkline(values: number[], options?: { width?: number; height?: number; stroke?: string }): SVGElement | null;
  renderBars(values: number[], options?: { width?: number; height?: number; gap?: number; fill?: string }): SVGElement | null;
  renderHeatmap(values: number[], options?: { width?: number; height?: number; minColor?: string; maxColor?: string }): SVGElement | null;

  // Diagnostics
  getStats(): Record<string, any>;
  getMemoryFootprint(): { rows: number; columns: number; lookupCacheSize: number; regexCacheSize: number; validationErrorsSize: number; pluginsSize: number };
  clearCaches(): void;
  bench(label: string, fn: () => any | Promise<any>, options?: { runs?: number; warmup?: number }): Promise<TableCrafterTypes.BenchResult>;
  benchRender(options?: { runs?: number; warmup?: number }): Promise<TableCrafterTypes.BenchResult>;
  benchFilter(query?: string, options?: { runs?: number; warmup?: number }): Promise<TableCrafterTypes.BenchResult>;
  snapshotHTML(options?: { scope?: 'table' | 'wrapper' }): string;

  // Virtual scrolling (foundation)
  computeVirtualWindow(args: { scrollTop: number; viewportHeight: number; rowHeight: number; totalRows: number; overscan?: number }):
    { startIndex: number; endIndex: number; topPadding: number; bottomPadding: number };
  enableVirtualScroll(options: { rowHeight: number; viewportHeight: number; overscan?: number }): void;
  disableVirtualScroll(): void;
  isVirtualScrolling(): boolean;

  // Static helpers
  static bootstrap(scope?: string | Element): Map<HTMLElement, TableCrafter>;
  static getBrowserSupport(): TableCrafterTypes.BrowserSupport;
  static minimumBrowserSupportNotice(): string;
}

export = TableCrafter;
export as namespace TableCrafter;
