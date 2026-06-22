<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap tc-welcome-wrap">
    <div class="tc-welcome-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
        <div class="tc-welcome-main">
            <!-- Integrated Header -->
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 28px; font-weight: 700; margin: 0 0 8px; color: #1d2327; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 32px;">🎉</span>
                    Welcome to TableCrafter
                </h1>
                <p style="font-size: 16px; color: #646970; margin: 0; line-height: 1.5;">You're 30 seconds away from your first dynamic table. Here's a live demo:</p>
            </div>
            <!-- Live Demo Table -->
            <div class="card" style="padding: 0; overflow: hidden; max-width: none; margin-bottom: 20px;">
                <div style="padding: 20px 24px; border-bottom: 1px solid #f0f0f1;">
                    <h2 style="margin: 0; font-size: 18px; font-weight: 600;">✨ Live Demo Table</h2>
                    <p style="margin: 8px 0 12px; font-size: 14px; color: #646970;">Try all features: search, sort, filter dropdowns, export buttons & pagination</p>
                    
                    <!-- Feature Toggles -->
                    <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 8px;">
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center; justify-content: space-between;">
                            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: #1e293b; cursor: pointer; padding: 6px 12px; border-radius: 6px; background: white; border: 1px solid #cbd5e1; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05);" onmouseover="this.style.borderColor='#3b82f6'" onmouseout="this.style.borderColor='#cbd5e1'">
                                        <input type="checkbox" id="tc-demo-search" checked style="margin: 0; accent-color: #3b82f6;">
                                        🔍 Search
                                    </label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: #1e293b; cursor: pointer; padding: 6px 12px; border-radius: 6px; background: white; border: 1px solid #cbd5e1; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05);" onmouseover="this.style.borderColor='#10b981'" onmouseout="this.style.borderColor='#cbd5e1'">
                                        <input type="checkbox" id="tc-demo-filters" checked style="margin: 0; accent-color: #10b981;">
                                        🏷️ Filters
                                    </label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: #1e293b; cursor: pointer; padding: 6px 12px; border-radius: 6px; background: white; border: 1px solid #cbd5e1; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05);" onmouseover="this.style.borderColor='#f59e0b'" onmouseout="this.style.borderColor='#cbd5e1'">
                                        <input type="checkbox" id="tc-demo-export" checked style="margin: 0; accent-color: #f59e0b;">
                                        📊 Export
                                    </label>
                                </div>
                            </div>
                            <div>
                                <button id="tc-refresh-demo" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border: none; color: white; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 4px rgba(99, 102, 241, 0.2); transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 8px rgba(99, 102, 241, 0.3)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(99, 102, 241, 0.2)'">
                                    🔄 Apply Changes
                                </button>
                            </div>
                        </div>
                        <div style="margin-top: 8px; font-size: 12px; color: #64748b; text-align: center;">
                            ↑ <strong>Interactive Demo:</strong> Toggle features on/off to see how they affect your table
                        </div>
                    </div>
                </div>
                <div style="padding: 24px;">
                    <!-- Demo Table Container -->
                    <div id="tc-welcome-demo-table" class="tablecrafter-container" style="margin-bottom: 20px;">
                        <div style="text-align: center; padding: 40px; color: #646970;">
                            <div style="font-size: 18px; margin-bottom: 8px;">🔄</div>
                            Loading your sample table...
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div style="text-align: center; padding: 16px 0; border-top: 1px solid #f0f0f1; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tablecrafter-wp-data-tables')); ?>" 
                           id="tc-customize-table-btn"
                           class="button button-primary button-hero" 
                           style="background: #2563eb; border-color: #2563eb; box-shadow: 0 4px 16px rgba(37, 99, 235, 0.3); color: white; font-size: 16px; font-weight: 600; padding: 14px 24px; height: auto; min-width: 200px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border-radius: 6px;">
                            🎨 Customize This Table
                        </a>
                        <button id="tc-try-different-data" class="button" 
                                style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border: 2px solid #d97706; color: white; font-size: 16px; font-weight: 600; padding: 14px 24px; height: auto; min-width: 200px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s ease; box-shadow: 0 2px 8px rgba(217, 119, 6, 0.2);">
                            🔄 Try Different Data
                        </button>
                    </div>
                    
                    <div style="text-align: center; margin-top: 16px;">
                        <p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                            ↑ <strong>Try it now:</strong> Search, click column headers to sort, use filter dropdowns, or export data!
                        </p>
                    </div>
                </div>
            </div>

            <div class="card" style="padding: 24px; max-width: none; margin-top: 20px;">
                <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 600;">📚 Resources</h3>
                <ul style="list-style: disc; margin: 0 0 0 20px; padding: 0;">
                    <li style="margin-bottom: 10px; line-height: 1.6;"><a
                            href="https://github.com/TableCrafter/wp-data-tables?tab=readme-ov-file#-tablecrafter-wordpress-data-tables--dynamic-content-plugin"
                            target="_blank">Documentation</a> -
                        Full usage guide.</li>
                    <li style="margin-bottom: 10px; line-height: 1.6;"><a
                            href="https://tastewp.org/plugins/tablecrafter-wp-data-tables" target="_blank">Live Demo</a>
                        - Try it in a sandbox.</li>
                    <li style="line-height: 1.6;"><a
                            href="https://wordpress.org/support/plugin/tablecrafter-wp-data-tables/"
                            target="_blank">Support Forum</a> - Get help.</li>
                </ul>
            </div>
        </div>

        <div class="tc-welcome-sidebar">
            <!-- Quick Tips -->
            <div class="card" style="margin-top: 0; max-width: none; padding: 28px; border-left: 4px solid #3b82f6; background: linear-gradient(135deg, #f0f7ff 0%, #e0f2fe 100%);">
                <h3 style="margin: 0 0 20px; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 10px; color: #1e40af;">
                    💡 Explore Table Features
                </h3>
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <li style="margin-bottom: 14px; padding-left: 24px; position: relative; font-size: 15px; line-height: 1.4; color: #334155;">
                        <span style="position: absolute; left: 0; top: 2px; font-size: 16px;">🔍</span>
                        <strong>Live Search:</strong> Type in the search box to filter rows instantly
                    </li>
                    <li style="margin-bottom: 14px; padding-left: 24px; position: relative; font-size: 15px; line-height: 1.4; color: #334155;">
                        <span style="position: absolute; left: 0; top: 2px; font-size: 16px;">↕️</span>
                        <strong>Smart Sorting:</strong> Click any column header to sort ascending/descending
                    </li>
                    <li style="margin-bottom: 14px; padding-left: 24px; position: relative; font-size: 15px; line-height: 1.4; color: #334155;">
                        <span style="position: absolute; left: 0; top: 2px; font-size: 16px;">🏷️</span>
                        <strong>Column Filters:</strong> Add dropdown filters for specific data types
                    </li>
                    <li style="margin-bottom: 14px; padding-left: 24px; position: relative; font-size: 15px; line-height: 1.4; color: #334155;">
                        <span style="position: absolute; left: 0; top: 2px; font-size: 16px;">📊</span>
                        <strong>Export Options:</strong> Download as CSV, Excel, or PDF with one click
                    </li>
                    <li style="margin-bottom: 14px; padding-left: 24px; position: relative; font-size: 15px; line-height: 1.4; color: #334155;">
                        <span style="position: absolute; left: 0; top: 2px; font-size: 16px;">📱</span>
                        <strong>Mobile Ready:</strong> Tables automatically stack on phones and tablets
                    </li>
                    <li style="padding-left: 24px; position: relative; font-size: 15px; line-height: 1.4; color: #334155;">
                        <span style="position: absolute; left: 0; top: 2px; font-size: 16px;">🔄</span>
                        <strong>Try Different Data:</strong> Switch between Users, Products, and Metrics demos
                    </li>
                </ul>
            </div>

            <!-- Lead Magnet - Enhanced for Conversions -->
            <div class="card"
                style="max-width: none; border-left: 4px solid #dc2626; background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); padding: 24px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);">
                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="font-size: 32px; margin-bottom: 12px; line-height: 1;">🚀</div>
                    <h3 style="margin: 0 0 8px; font-size: 18px; font-weight: 700; color: #dc2626;">Free Guide: 50+ Data Source Ideas</h3>
                    <p style="margin: 0; font-size: 14px; color: #7f1d1d; line-height: 1.4; font-weight: 500;">Public APIs, CSVs &amp; Google Sheets you can connect with TableCrafter</p>
                </div>

                <!-- Value Props -->
                <div style="margin-bottom: 20px; font-size: 13px; color: #991b1b;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                        <span style="color: #16a34a; font-weight: bold;">✓</span>
                        <span>Financial APIs (stocks, crypto, forex)</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                        <span style="color: #16a34a; font-weight: bold;">✓</span>
                        <span>E-commerce &amp; product data examples</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #16a34a; font-weight: bold;">✓</span>
                        <span>SaaS metrics &amp; analytics sources</span>
                    </div>
                </div>
                
                <form id="tc-lead-form">
                    <input type="email" id="tc-email" placeholder="Enter your email address" required
                        style="width: 100%; padding: 14px 16px; border: 2px solid #dc2626; border-radius: 8px; font-size: 15px; margin-bottom: 16px; box-sizing: border-box; background: white; color: #374151;">
                    <div style="text-align: center;">
                        <button type="submit" class="button button-primary"
                            style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); border: 2px solid #b91c1c; color: white; padding: 14px 28px; font-size: 15px; font-weight: 700; width: 100%; height: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); text-transform: uppercase; letter-spacing: 0.5px;">
                            Get Free Guide Now →
                        </button>
                    </div>
                    <p style="margin: 12px 0 0; font-size: 11px; color: #6b7280; text-align: center; line-height: 1.4;">
                        Instant download • No spam • 2,000+ developers trust us
                    </p>
                </form>
                <div id="tc-lead-success"
                    style="display: none; padding: 20px; background: #dcfce7; border-radius: 6px; text-align: center; margin-top: 16px;">
                    <p style="margin: 0; font-size: 14px; font-weight: 600; color: #166534;">✅ Check your email!</p>
                    <p style="margin: 8px 0 0; font-size: 12px; color: #15803d; line-height: 1.5;">Your guide is on its
                        way</p>
                </div>
            </div>

            <script>
                document.getElementById('tc-lead-form').addEventListener('submit', function (e) {
                    e.preventDefault();
                    var email = document.getElementById('tc-email').value;

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=tc_subscribe_lead&email=' + encodeURIComponent(email) + '&nonce=<?php echo wp_create_nonce('tc_lead_nonce'); ?>'
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('tc-lead-form').style.display = 'none';
                                document.getElementById('tc-lead-success').style.display = 'block';
                            }
                        })
                        .catch(error => console.error('Error:', error));
                });
            </script>

            <!-- Welcome Demo Table Script -->
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const demoContainer = document.getElementById('tc-welcome-demo-table');
                    const tryDifferentBtn = document.getElementById('tc-try-different-data');
                    const customizeBtn = document.getElementById('tc-customize-table-btn');
                    
                    // Demo data sources
                    const demoSources = [
                        {
                            url: '<?php echo esc_url(TABLECRAFTER_URL . 'demo-data/users.json'); ?>',
                            name: 'User Directory',
                            icon: '👤'
                        },
                        {
                            url: '<?php echo esc_url(TABLECRAFTER_URL . 'demo-data/products.json'); ?>',
                            name: 'Product Inventory', 
                            icon: '📦'
                        },
                        {
                            url: '<?php echo esc_url(TABLECRAFTER_URL . 'demo-data/metrics.json'); ?>',
                            name: 'Sales Metrics',
                            icon: '📈'
                        }
                    ];
                    
                    let currentDemoIndex = 0;
                    
                    // Update customize button to pre-populate dashboard
                    function updateCustomizeButton() {
                        if (customizeBtn) {
                            const currentSource = demoSources[currentDemoIndex];
                            const dashboardUrl = '<?php echo esc_url(admin_url('admin.php?page=tablecrafter-wp-data-tables')); ?>';
                            customizeBtn.href = dashboardUrl + '&demo_url=' + encodeURIComponent(currentSource.url);
                        }
                    }
                    
                    function initDemoTable(sourceIndex = 0) {
                        const source = demoSources[sourceIndex];
                        
                        // Get current toggle states
                        const searchEnabled = document.getElementById('tc-demo-search')?.checked ?? true;
                        const filtersEnabled = document.getElementById('tc-demo-filters')?.checked ?? true;
                        const exportEnabled = document.getElementById('tc-demo-export')?.checked ?? true;
                        
                        // Update UI to show what's loading
                        demoContainer.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #646970;">
                                <div style="font-size: 18px; margin-bottom: 8px;">${source.icon}</div>
                                Loading ${source.name}...
                            </div>
                        `;
                        
                        if (typeof TableCrafter !== 'undefined') {
                            try {
                                const config = {
                                    data: source.url,
                                    perPage: 10, // Show more rows to demonstrate filtering
                                    pagination: true, // Enable pagination to show more functionality
                                    globalSearch: searchEnabled,
                                    filterable: filtersEnabled,
                                    exportable: exportEnabled,
                                    responsive: true,
                                    api: {
                                        proxy: {
                                            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                                            nonce: '<?php echo wp_create_nonce('tc_proxy_nonce'); ?>'
                                        }
                                    }
                                };
                                
                                console.log('Welcome Demo: Initializing TableCrafter with config:', config);
                                new TableCrafter('#tc-welcome-demo-table', config);
                            } catch (error) {
                                console.error('Welcome Demo: TableCrafter initialization error:', error);
                                demoContainer.innerHTML = `
                                    <div style="text-align: center; padding: 40px; color: #d63638;">
                                        <div style="font-size: 18px; margin-bottom: 8px;">⚠️</div>
                                        Demo temporarily unavailable
                                    </div>
                                `;
                            }
                        } else {
                            // Fallback if TableCrafter library not loaded - show static demo
                            console.warn('Welcome Demo: TableCrafter library not loaded, showing static demo');
                            demoContainer.innerHTML = `
                                <div style="border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">
                                    <div style="background: #f8f9fa; padding: 12px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-weight: 600; color: #495057;">User Directory Demo</span>
                                        <input type="search" placeholder="Search..." style="padding: 6px 12px; border: 1px solid #ced4da; border-radius: 4px; width: 200px;">
                                    </div>
                                    <table style="width: 100%; border-collapse: collapse;">
                                        <thead style="background: #e9ecef;">
                                            <tr>
                                                <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: 600;">Name ↕</th>
                                                <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: 600;">Email ↕</th>
                                                <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: 600;">Role ↕</th>
                                                <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: 600;">Status ↕</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">John Doe</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">john.doe@example.com</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">Administrator</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;"><span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 12px; font-size: 12px;">Active</span></td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">Jane Smith</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">jane.smith@example.com</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">Editor</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;"><span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 12px; font-size: 12px;">Active</span></td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">Robert Johnson</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">bob.j@example.net</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;">Subscriber</td>
                                                <td style="padding: 12px; border-bottom: 1px solid #f1f3f4;"><span style="background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 12px; font-size: 12px;">Inactive</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div style="padding: 12px; background: #f8f9fa; border-top: 1px solid #ddd; font-size: 13px; color: #6c757d; text-align: center;">
                                        Static preview - Click "Customize This Table" for full functionality
                                    </div>
                                </div>
                            `;
                        }
                        
                        // Update the customize button URL
                        updateCustomizeButton();
                    }
                    
                    // Try Different Data button
                    if (tryDifferentBtn) {
                        tryDifferentBtn.addEventListener('click', function() {
                            currentDemoIndex = (currentDemoIndex + 1) % demoSources.length;
                            initDemoTable(currentDemoIndex);
                        });
                    }
                    
                    // Toggle event listeners
                    const refreshBtn = document.getElementById('tc-refresh-demo');
                    const searchToggle = document.getElementById('tc-demo-search');
                    const filtersToggle = document.getElementById('tc-demo-filters');
                    const exportToggle = document.getElementById('tc-demo-export');
                    
                    // Auto-refresh on toggle changes
                    [searchToggle, filtersToggle, exportToggle].forEach(toggle => {
                        if (toggle) {
                            toggle.addEventListener('change', function() {
                                setTimeout(() => initDemoTable(currentDemoIndex), 100); // Small delay for better UX
                            });
                        }
                    });
                    
                    // Manual refresh button
                    if (refreshBtn) {
                        refreshBtn.addEventListener('click', function() {
                            initDemoTable(currentDemoIndex);
                        });
                    }
                    
                    // Initialize with first demo
                    setTimeout(() => {
                        console.log('Welcome Demo: TableCrafter available?', typeof TableCrafter !== 'undefined');
                        console.log('Welcome Demo: Available scripts:', document.scripts.length);
                        initDemoTable(0);
                    }, 500); // Small delay for better UX
                });
            </script>

            <div class="card" style="max-width: none; border-left: 4px solid #6366f1; padding: 24px;">
                <h3 style="margin: 0 0 12px; font-size: 16px; font-weight: 600;">Need More Power?</h3>
                <p style="margin: 0 0 16px; line-height: 1.6;"><strong>Gravity Tables (Pro)</strong> allows you to turn
                    Gravity Forms submissions into editable,
                    searchable tables on the frontend.</p>
                <ul style="margin: 0 0 20px 20px; padding: 0; list-style: circle;">
                    <li style="margin-bottom: 6px;">Edit Entries Frontend</li>
                    <li style="margin-bottom: 6px;">Advanced Filtering</li>
                    <li>Row Deletion</li>
                </ul>
                <a href="https://checkout.freemius.com/plugin/20996/plan/35031/" target="_blank"
                    class="button button-secondary"
                    style="width: 100%; text-align: center; box-sizing: border-box;">Learn More</a>
            </div>

            <div class="card" style="max-width: none; background: #fafafa; padding: 16px;">
                <p style="margin: 0; text-align: center; font-size: 12px; color: #888;">
                    Made with ❤️ by the TableCrafter Team
                </p>
            </div>
        </div>
    </div>
</div>