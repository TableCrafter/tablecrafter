/**
 * TableCrafter Real-time Collaboration System
 * Enables multiple users to collaborate on the same table in real-time
 * @version 1.0.0
 * @author TableCrafter Team
 * @license MIT
 */

/**
 * Real-time collaboration manager for TableCrafter tables
 * Uses WordPress REST API and polling for real-time updates (WebSocket fallback for future)
 */
class TableCrafterCollaboration {
  constructor(tableInstance) {
    this.table = tableInstance;
    this.tableId = this.generateTableId();
    this.userId = this.getCurrentUserId();
    this.sessionId = this.generateSessionId();
    this.isEnabled = false;
    
    // Collaboration state
    this.connectedUsers = new Map();
    this.lastSyncTime = Date.now();
    this.syncInterval = null;
    this.cursorTimeout = null;
    
    // Configuration
    this.config = {
      enabled: false,
      syncInterval: 2000, // 2 seconds for REST API polling
      showUserPresence: true,
      showLiveCursors: true,
      syncFilters: true,
      syncSorting: true,
      syncPagination: false, // Usually disabled to avoid jarring UX
      maxUsers: 25,
      ...this.table.config.collaboration || {}
    };

    // Event handlers
    this.boundHandlers = {
      sort: this.handleSort.bind(this),
      filter: this.handleFilter.bind(this),
      paginate: this.handlePaginate.bind(this),
      mousemove: this.handleMouseMove.bind(this),
      beforeunload: this.handleBeforeUnload.bind(this)
    };

    TC_DEBUG.log('Collaboration initialized for table:', this.tableId);
  }

  /**
   * Enable real-time collaboration for this table
   */
  enable() {
    if (this.isEnabled) return;

    // Check WordPress user capability and nonce
    if (!this.canCollaborate()) {
      TC_DEBUG.warn('User lacks collaboration permissions');
      return false;
    }

    this.isEnabled = true;
    
    // Register with collaboration server
    this.joinCollaborationSession();
    
    // Set up event listeners
    this.attachEventListeners();
    
    // Start sync loop
    this.startSyncLoop();
    
    // Show collaboration UI
    this.showCollaborationUI();
    
    TC_DEBUG.log('Collaboration enabled for table:', this.tableId);
    return true;
  }

  /**
   * Disable real-time collaboration
   */
  disable() {
    if (!this.isEnabled) return;

    this.isEnabled = false;
    
    // Leave collaboration session
    this.leaveCollaborationSession();
    
    // Remove event listeners
    this.detachEventListeners();
    
    // Stop sync loop
    this.stopSyncLoop();
    
    // Hide collaboration UI
    this.hideCollaborationUI();
    
    TC_DEBUG.log('Collaboration disabled for table:', this.tableId);
  }

  /**
   * Join the collaboration session for this table
   */
  async joinCollaborationSession() {
    try {
      const response = await this.makeCollaborationRequest('join', {
        table_id: this.tableId,
        user_id: this.userId,
        session_id: this.sessionId,
        table_config: {
          columns: this.table.config.columns.map(col => ({ 
            field: col.field, 
            title: col.title 
          })),
          features_enabled: {
            sortable: this.table.config.sortable,
            filterable: this.table.config.filterable,
            pagination: this.table.config.pagination
          }
        }
      });

      if (response.success) {
        this.connectedUsers = new Map(response.data.users || []);
        this.lastSyncTime = Date.now();
        TC_DEBUG.log('Joined collaboration session with', this.connectedUsers.size, 'users');
      }
    } catch (error) {
      TC_DEBUG.error('Failed to join collaboration session:', error);
    }
  }

  /**
   * Leave the collaboration session
   */
  async leaveCollaborationSession() {
    try {
      await this.makeCollaborationRequest('leave', {
        table_id: this.tableId,
        session_id: this.sessionId
      });
    } catch (error) {
      TC_DEBUG.error('Failed to leave collaboration session:', error);
    }
  }

  /**
   * Handle sorting events for collaboration
   */
  handleSort(field, direction) {
    if (!this.isEnabled || !this.config.syncSorting) return;

    this.broadcastEvent('sort', {
      field: field,
      direction: direction,
      timestamp: Date.now()
    });
  }

  /**
   * Handle filtering events for collaboration
   */
  handleFilter(filters) {
    if (!this.isEnabled || !this.config.syncFilters) return;

    this.broadcastEvent('filter', {
      filters: filters,
      timestamp: Date.now()
    });
  }

  /**
   * Handle pagination events for collaboration
   */
  handlePaginate(page, pageSize) {
    if (!this.isEnabled || !this.config.syncPagination) return;

    this.broadcastEvent('paginate', {
      page: page,
      pageSize: pageSize,
      timestamp: Date.now()
    });
  }

  /**
   * Handle mouse movement for live cursors
   */
  handleMouseMove(event) {
    if (!this.isEnabled || !this.config.showLiveCursors) return;

    // Throttle mouse movement events
    if (this.cursorTimeout) return;
    
    this.cursorTimeout = setTimeout(() => {
      this.cursorTimeout = null;
      
      // Get position relative to table container
      const rect = this.table.container.getBoundingClientRect();
      const x = ((event.clientX - rect.left) / rect.width) * 100;
      const y = ((event.clientY - rect.top) / rect.height) * 100;
      
      this.broadcastEvent('cursor', {
        x: x,
        y: y,
        timestamp: Date.now()
      });
    }, 100); // 100ms throttle
  }

  /**
   * Handle page unload to clean up collaboration session
   */
  handleBeforeUnload() {
    if (this.isEnabled) {
      // Use sendBeacon for reliable cleanup on page unload
      navigator.sendBeacon(
        `${this.getRestUrl()}/collaboration/leave`,
        JSON.stringify({
          table_id: this.tableId,
          session_id: this.sessionId
        })
      );
    }
  }

  /**
   * Broadcast an event to other collaborators
   */
  async broadcastEvent(eventType, data) {
    try {
      await this.makeCollaborationRequest('broadcast', {
        table_id: this.tableId,
        session_id: this.sessionId,
        event_type: eventType,
        event_data: data
      });
    } catch (error) {
      TC_DEBUG.error('Failed to broadcast event:', error);
    }
  }

  /**
   * Start the sync loop to receive updates from other users
   */
  startSyncLoop() {
    if (this.syncInterval) return;

    this.syncInterval = setInterval(() => {
      this.syncWithServer();
    }, this.config.syncInterval);
  }

  /**
   * Stop the sync loop
   */
  stopSyncLoop() {
    if (this.syncInterval) {
      clearInterval(this.syncInterval);
      this.syncInterval = null;
    }
  }

  /**
   * Sync with server to get updates from other users
   */
  async syncWithServer() {
    try {
      const response = await this.makeCollaborationRequest('sync', {
        table_id: this.tableId,
        session_id: this.sessionId,
        last_sync: this.lastSyncTime
      });

      if (response.success && response.data.events) {
        this.processIncomingEvents(response.data.events);
        this.updateConnectedUsers(response.data.users);
        this.lastSyncTime = Date.now();
      }
    } catch (error) {
      TC_DEBUG.error('Sync failed:', error);
    }
  }

  /**
   * Process incoming collaboration events from other users
   */
  processIncomingEvents(events) {
    events.forEach(event => {
      // Ignore our own events
      if (event.session_id === this.sessionId) return;

      switch (event.event_type) {
        case 'sort':
          this.applySortFromCollaborator(event);
          break;
        case 'filter':
          this.applyFilterFromCollaborator(event);
          break;
        case 'paginate':
          this.applyPaginateFromCollaborator(event);
          break;
        case 'cursor':
          this.showCollaboratorCursor(event);
          break;
      }
    });
  }

  /**
   * Apply sort from collaborator
   */
  applySortFromCollaborator(event) {
    if (!this.config.syncSorting) return;

    const { field, direction } = event.event_data;
    
    // Apply sort without triggering our own broadcast
    this.detachEventListeners();
    this.table.sort(field, direction);
    this.attachEventListeners();
    
    this.showCollaborationNotification(`User sorted by ${field} (${direction})`);
  }

  /**
   * Apply filter from collaborator
   */
  applyFilterFromCollaborator(event) {
    if (!this.config.syncFilters) return;

    const { filters } = event.event_data;
    
    // Apply filters without triggering our own broadcast
    this.detachEventListeners();
    this.table.applyFilters(filters);
    this.attachEventListeners();
    
    this.showCollaborationNotification('Filters updated by collaborator');
  }

  /**
   * Apply pagination from collaborator
   */
  applyPaginateFromCollaborator(event) {
    if (!this.config.syncPagination) return;

    const { page, pageSize } = event.event_data;
    
    // Apply pagination without triggering our own broadcast
    this.detachEventListeners();
    this.table.goToPage(page);
    this.attachEventListeners();
    
    this.showCollaborationNotification(`Page changed to ${page}`);
  }

  /**
   * Show collaborator cursor position
   */
  showCollaboratorCursor(event) {
    if (!this.config.showLiveCursors) return;

    const userId = event.user_id;
    const { x, y } = event.event_data;
    
    // Get or create cursor element for this user
    let cursor = document.getElementById(`tc-cursor-${userId}`);
    if (!cursor) {
      cursor = document.createElement('div');
      cursor.id = `tc-cursor-${userId}`;
      cursor.className = 'tc-collaborator-cursor';
      cursor.innerHTML = `
        <div class="tc-cursor-pointer"></div>
        <div class="tc-cursor-label">${this.getUserName(userId)}</div>
      `;
      this.table.container.appendChild(cursor);
    }
    
    // Position cursor
    cursor.style.left = `${x}%`;
    cursor.style.top = `${y}%`;
    cursor.style.display = 'block';
    
    // Hide cursor after inactivity
    setTimeout(() => {
      cursor.style.display = 'none';
    }, 3000);
  }

  /**
   * Show collaboration UI elements
   */
  showCollaborationUI() {
    // Create user presence indicator
    if (this.config.showUserPresence) {
      this.createUserPresenceIndicator();
    }
    
    // Add collaboration controls
    this.createCollaborationControls();
  }

  /**
   * Hide collaboration UI elements
   */
  hideCollaborationUI() {
    // Remove user presence indicator
    const presence = this.table.container.querySelector('.tc-user-presence');
    if (presence) presence.remove();
    
    // Remove collaboration controls
    const controls = this.table.container.querySelector('.tc-collaboration-controls');
    if (controls) controls.remove();
    
    // Remove all collaborator cursors
    this.table.container.querySelectorAll('.tc-collaborator-cursor')
      .forEach(cursor => cursor.remove());
  }

  /**
   * Create user presence indicator
   */
  createUserPresenceIndicator() {
    const presence = document.createElement('div');
    presence.className = 'tc-user-presence';
    presence.innerHTML = `
      <div class="tc-presence-icon">👥</div>
      <div class="tc-presence-count">${this.connectedUsers.size}</div>
      <div class="tc-presence-tooltip">
        ${Array.from(this.connectedUsers.values())
          .map(user => `<div>${user.name}</div>`)
          .join('')}
      </div>
    `;
    
    this.table.container.appendChild(presence);
  }

  /**
   * Update connected users display
   */
  updateConnectedUsers(users) {
    this.connectedUsers = new Map(users || []);
    
    // Update presence count
    const presenceCount = this.table.container.querySelector('.tc-presence-count');
    if (presenceCount) {
      presenceCount.textContent = this.connectedUsers.size;
    }
    
    // Update tooltip
    const tooltip = this.table.container.querySelector('.tc-presence-tooltip');
    if (tooltip) {
      tooltip.innerHTML = Array.from(this.connectedUsers.values())
        .map(user => `<div>${user.name}</div>`)
        .join('');
    }
  }

  /**
   * Create collaboration controls
   */
  createCollaborationControls() {
    const controls = document.createElement('div');
    controls.className = 'tc-collaboration-controls';
    controls.innerHTML = `
      <button class="tc-sync-toggle" data-enabled="${this.config.syncSorting}">
        ${this.config.syncSorting ? '🔗' : '🔗'} Sync Views
      </button>
      <button class="tc-leave-session">
        ❌ Leave Session
      </button>
    `;
    
    // Add event listeners
    controls.querySelector('.tc-sync-toggle').addEventListener('click', () => {
      this.toggleSyncMode();
    });
    
    controls.querySelector('.tc-leave-session').addEventListener('click', () => {
      this.disable();
    });
    
    this.table.container.appendChild(controls);
  }

  /**
   * Toggle sync mode for views
   */
  toggleSyncMode() {
    this.config.syncSorting = !this.config.syncSorting;
    this.config.syncFilters = this.config.syncSorting;
    
    const button = this.table.container.querySelector('.tc-sync-toggle');
    button.textContent = this.config.syncSorting ? '🔗 Sync Views' : '🔗 Independent Views';
    button.dataset.enabled = this.config.syncSorting;
  }

  /**
   * Show temporary collaboration notification
   */
  showCollaborationNotification(message) {
    const notification = document.createElement('div');
    notification.className = 'tc-collaboration-notification';
    notification.textContent = message;
    
    this.table.container.appendChild(notification);
    
    setTimeout(() => {
      notification.remove();
    }, 3000);
  }

  /**
   * Attach event listeners for collaboration
   */
  attachEventListeners() {
    // Override table's sort method to include collaboration
    if (this.table.originalSort) return; // Already attached
    
    this.table.originalSort = this.table.sort.bind(this.table);
    this.table.sort = (field, direction) => {
      this.table.originalSort(field, direction);
      this.handleSort(field, direction);
    };
    
    // Add mouse move listener for cursors
    this.table.container.addEventListener('mousemove', this.boundHandlers.mousemove);
    
    // Add page unload listener
    window.addEventListener('beforeunload', this.boundHandlers.beforeunload);
  }

  /**
   * Detach event listeners for collaboration
   */
  detachEventListeners() {
    // Restore original table methods
    if (this.table.originalSort) {
      this.table.sort = this.table.originalSort;
      delete this.table.originalSort;
    }
    
    // Remove mouse move listener
    this.table.container.removeEventListener('mousemove', this.boundHandlers.mousemove);
    
    // Remove page unload listener
    window.removeEventListener('beforeunload', this.boundHandlers.beforeunload);
  }

  /**
   * Make REST API request to collaboration endpoint
   */
  async makeCollaborationRequest(action, data) {
    const url = `${this.getRestUrl()}/collaboration/${action}`;
    
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': this.getNonce()
      },
      body: JSON.stringify(data)
    });
    
    if (!response.ok) {
      throw new Error(`Collaboration request failed: ${response.status}`);
    }
    
    return await response.json();
  }

  /**
   * Generate unique table ID based on container and configuration
   */
  generateTableId() {
    const containerId = this.table.container.id || this.table.container.className;
    const configHash = this.hashCode(JSON.stringify(this.table.config.columns));
    return `tc_${containerId}_${configHash}`;
  }

  /**
   * Generate unique session ID for this browser session
   */
  generateSessionId() {
    return 'sess_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
  }

  /**
   * Get current WordPress user ID
   */
  getCurrentUserId() {
    return window.tablecrafterData?.user_id || 0;
  }

  /**
   * Get user display name by ID
   */
  getUserName(userId) {
    const user = this.connectedUsers.get(userId);
    return user ? user.name : `User ${userId}`;
  }

  /**
   * Check if user can collaborate
   */
  canCollaborate() {
    return window.tablecrafterData?.can_collaborate || false;
  }

  /**
   * Get WordPress REST API URL
   */
  getRestUrl() {
    return window.tablecrafterData?.rest_url || '/wp-json/tablecrafter/v1';
  }

  /**
   * Get WordPress nonce for security
   */
  getNonce() {
    return window.tablecrafterData?.nonce || '';
  }

  /**
   * Generate hash code for string
   */
  hashCode(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
      const char = str.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash; // Convert to 32bit integer
    }
    return Math.abs(hash);
  }
}

// Auto-enable collaboration if configured
document.addEventListener('DOMContentLoaded', () => {
  // Wait for tables to initialize
  setTimeout(() => {
    if (window.TableCrafterInstances) {
      Object.values(window.TableCrafterInstances).forEach(table => {
        if (table.config.collaboration?.enabled) {
          if (!table.collaboration) {
            table.collaboration = new TableCrafterCollaboration(table);
          }
          table.collaboration.enable();
        }
      });
    }
  }, 1000);
});

// Export for use with existing TableCrafter instances
window.TableCrafterCollaboration = TableCrafterCollaboration;