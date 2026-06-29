/* TinyMCE plugin for Advanced Data Tables — Classic (TinyMCE) editor integration (#129) */
(function () {
    tinymce.PluginManager.add('gravity_tables', function (editor) {
        editor.addButton('gravity_tables', {
            title: 'Insert Gravity Table',
            icon: 'table',
            onclick: function () {
                editor.windowManager.open({
                    title: 'Insert Gravity Table',
                    body: [
                        {
                            type: 'textbox',
                            name: 'table_id',
                            label: 'Table ID',
                            value: ''
                        }
                    ],
                    onsubmit: function (e) {
                        var tableId = parseInt(e.data.table_id, 10);
                        if (tableId > 0) {
                            editor.insertContent('[gravity_table id="' + tableId + '"]');
                        } else {
                            editor.insertContent('[gravity_table id=""]');
                        }
                    }
                });
            }
        });
    });
})();
