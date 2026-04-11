jQuery(document).ready(function($) {

    function calculateWeights(sliderValue) {
        var userActivityWeight = 0.4;
        var serverLoadWeight = 0.3;
        var responseTimeWeight = 0.3;

        if (sliderValue < 0) {
            userActivityWeight += (0.1 * sliderValue);
            serverLoadWeight -= (0.1 * sliderValue / 2);
            responseTimeWeight -= (0.1 * sliderValue / 2);
        } else {
            userActivityWeight += (0.1 * sliderValue);
            serverLoadWeight -= (0.1 * sliderValue / 2);
            responseTimeWeight -= (0.1 * sliderValue / 2);
        }

        return {
            userActivityWeight: userActivityWeight,
            serverLoadWeight: serverLoadWeight,
            responseTimeWeight: responseTimeWeight
        };
    }

    function updateDisplay(sliderValue) {
        var weights = calculateWeights(sliderValue);

        $('#user_activity_weight_display').text(weights.userActivityWeight.toFixed(2));
        $('#server_load_weight_display').text(weights.serverLoadWeight.toFixed(2));
        $('#response_time_weight_display').text(weights.responseTimeWeight.toFixed(2));
    }

    $('#dfehc_priority_slider').on('input', function() {
        updateDisplay(parseInt($(this).val()));
    });

    function updateDisableHeartbeatCheckbox() {
        var backendEnabled = $('#dfhcsl_backend_heartbeat_control').prop('checked');
        var editorEnabled = $('#dfhcsl_editor_heartbeat_control').prop('checked');
        
        if (backendEnabled || editorEnabled) {
            $('#dfehc_disable_heartbeat').prop('disabled', true);
        } else {
            $('#dfehc_disable_heartbeat').prop('disabled', false);
        }
    }

    $('#dfhcsl_backend_heartbeat_control, #dfhcsl_editor_heartbeat_control').on('change', function() {
        updateDisableHeartbeatCheckbox();
    });

    $('#dfehc_disable_heartbeat').on('change', function() {
        var isChecked = $(this).prop('checked');
        
        if (isChecked) {
            $('#dfhcsl_backend_heartbeat_control, #dfhcsl_editor_heartbeat_control').prop('disabled', true).prop('checked', false);
        } else {
            $('#dfhcsl_backend_heartbeat_control, #dfhcsl_editor_heartbeat_control').prop('disabled', false);
        }
        
        updateDisableHeartbeatCheckbox();
    });
	
    updateDisplay(parseInt($('#dfehc_priority_slider').val()));
      $('#add_optimizations_to_menu').on('change', function() {
        var isChecked = $(this).prop('checked');
        if (isChecked) {
            $('<p><a href="' + admin_url('admin.php?page=dfehc-unclogger') + '">Manually choose certain database optimizations</a></p>').appendTo('#your_menu_container');
        } else {
            $('#your_menu_container p:contains("Manually choose certain database optimizations")').remove();
        }
    });
});