// assets/js/admin.js

jQuery(document).ready(function($) {
    // Initialize datepicker for expiry date fields
    $('.content-expiry-date').datepicker({
        dateFormat: 'yy-mm-dd',
        minDate: 0
    });

    // Bulk edit functionality
    var bulkEditRow = $('#bulk-edit');
    var bulkEditSubmit = bulkEditRow.find('#bulk_edit');

    bulkEditSubmit.on('click', function(e) {
        e.preventDefault();
        
        var expiryDate = bulkEditRow.find('input[name="content_expiry_date"]').val();
        var postIds = [];
        
        $('input[name="post[]"]:checked').each(function() {
            postIds.push($(this).val());
        });
        
        if (postIds.length && expiryDate) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                async: false,
                cache: false,
                data: {
                    action: 'bulk_edit_content_expiry',
                    post_ids: postIds,
                    expiry_date: expiryDate,
                    nonce: $('#content_expiry_nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        // Reload the page to show updated values
                        location.reload();
                    }
                }
            });
        }
    });

    // Analytics charts initialization
    if ($('#expiry-trends-chart').length) {
        var ctx = document.getElementById('expiry-trends-chart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: expiryTrendsData, // Data is localized via wp_localize_script
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Quick edit functionality
    $('.editinline').on('click', function() {
        var postId = $(this).closest('tr').attr('id').replace('post-', '');
        var expiryDate = $('#post-' + postId).find('.column-expiry_date').data('expiry');
        
        setTimeout(function() {
            $('#edit-' + postId).find('input[name="content_expiry_date"]').val(expiryDate);
        }, 200);
    });

    // Settings page validation
    $('#content-expiry-settings-form').on('submit', function(e) {
        var slackWebhook = $('#slack_webhook_url').val();
        if ($('#enable_slack').is(':checked') && !slackWebhook) {
            e.preventDefault();
            alert('Please enter a Slack webhook URL or disable Slack notifications.');
        }
    });

    // Preview expired content message
    $('#preview_expired_message').on('click', function(e) {
        e.preventDefault();
        var message = $('#expired_content_message').val();
        
        $('#message-preview').html(message).show();
    });

    // Dynamic backup settings
    $('#enable_backups').on('change', function() {
        $('.backup-settings').toggleClass('hidden', !$(this).is(':checked'));
    });

    // Restore content confirmation
    $('.restore-content').on('click', function(e) {
        if (!confirm('Are you sure you want to restore this content? This will revert it to its pre-expiry state.')) {
            e.preventDefault();
        }
    });
});