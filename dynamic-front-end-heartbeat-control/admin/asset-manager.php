<?php

namespace DynamicHeartbeat\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AssetManager {
    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_enqueue_scripts', [$this,'assets'] );
    }

    public function assets( $hook ) {
        if ( ! in_array($hook,['settings_page_dfehc_plugin','toplevel_page_dfehc-unclogger'],true) ) return;

        wp_enqueue_style ( 'dfhcsl-admin-css', plugin_dir_url(__FILE__).'../css/dfhcsl-admin.css',[], '1.3' );
        wp_enqueue_script( 'dfhcsl-admin-js', plugin_dir_url(__FILE__).'../js/dfhcsl-admin.js', ['jquery'], '1.3', true );

        $css = '
            .wrap form h2{margin-top:2em;padding-top:1.5em;border-top:1px solid #ddd}
            .wrap form h2:first-of-type{margin-top:0;padding-top:0;border-top:none}
            #dfehc-optimizer-form button{margin-right:5px;margin-bottom:5px}
            .database-health-status{display:inline-block;width:22px;height:22px;border-radius:7px;background:radial-gradient(circle at 30% 22%,rgba(255,255,255,.5) 0,transparent 40%),radial-gradient(ellipse at top,rgba(255,255,255,.28) 12%,transparent 55%),linear-gradient(to bottom,rgba(255,255,255,.05),rgba(0,0,0,.22)),radial-gradient(ellipse at top,var(--c) 72%,transparent 73%),radial-gradient(ellipse at bottom,var(--c) 72%,transparent 73%);background-size:100% 100%,100% 38%,100% 100%,100% 38%,100% 38%;background-position:center,top,center,top,bottom;background-repeat:no-repeat;animation:heartbeat 2.3s ease-in-out infinite;box-shadow:0 0 8px var(--c),0 5px 12px rgba(0,0,0,.32)}
            .dfehc-loader-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,.85);z-index:9999;text-align:center}
            .dfehc-loader-content{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%)}
            .heartbeat-loader{--c:#28a745;width:60px;height:60px;border-radius:50%;background:var(--c);animation:pulse 1.5s ease-in-out infinite;box-shadow:0 0 10px var(--c),0 0 20px var(--c),0 0 30px var(--c);margin:0 auto}
            @keyframes pulse{0%,100%{transform:scale(1);box-shadow:0 0 10px var(--c),0 0 20px var(--c)}50%{transform:scale(1.25);box-shadow:0 0 25px var(--c),0 0 45px var(--c),0 0 65px var(--c)}}
            .dfehc-tooltip{position:relative;display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background-color:#a0a5aa;color:#fff;font-size:10px;font-weight:bold;cursor:help;user-select:none;margin-top:-2px}
            .dfehc-tooltip .dfehc-tooltip-text{visibility:hidden;width:250px;background-color:#333;color:#fff;text-align:center;border-radius:6px;padding:8px;position:absolute;z-index:1;bottom:150%;left:50%;margin-left:-125px;opacity:0;transition:opacity .3s;font-size:12px;font-weight:normal}
            .dfehc-tooltip .dfehc-tooltip-text::after{content:"";position:absolute;top:100%;left:50%;margin-left:-5px;border-width:5px;border-style:solid;border-color:#333 transparent transparent transparent}
            .dfehc-tooltip:hover .dfehc-tooltip-text{visibility:visible;opacity:1}
        ';
        wp_add_inline_style( 'dfhcsl-admin-css', $css );

        if ( $hook === 'settings_page_dfehc_plugin' ) {
          $tabs_css = '
    .dfehc-tabs{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin:16px 0 0;
        padding:0;
        border-bottom:1px solid #dcdcde;
    }
    .dfehc-tabs .nav-tab{
        float:none;
        margin:0;
        border:1px solid #dcdcde;
        border-bottom:none;
        background:#f6f7f7;
        padding:9px 14px;
        line-height:1.2;
        border-top-left-radius:10px;
        border-top-right-radius:10px;
        font-weight:600;
        color:#1d2327;
        display:inline-flex;
        align-items:center;
        gap:8px;
        position:relative;
        top:1px;
        transition:background-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .dfehc-tabs .nav-tab:hover{
        background:#fff;
        box-shadow:0 1px 0 rgba(0,0,0,.02);
    }
    .dfehc-tabs .nav-tab:focus{
        outline:none;
        box-shadow:0 0 0 2px rgba(34,113,177,.35);
    }
    .dfehc-tabs .nav-tab.nav-tab-active{
        background:#fff;
        border-color:#dcdcde;
        color:#1d2327;
        box-shadow:0 -1px 0 #fff inset;
    }

    .dfehc-tab-panel{
        display:none;
        background:#fff;
        border:1px solid #dcdcde;
        border-top:none;
        padding:18px 18px 8px;
        border-bottom-left-radius:12px;
        border-bottom-right-radius:12px;
        box-shadow:0 1px 2px rgba(0,0,0,.04);
    }
    .dfehc-tab-panel.is-active{display:block}

    .dfehc-tab-panel h2{
        margin:0 0 8px;
        padding:0;
        border:0;
        font-size:14px;
        font-weight:700;
    }
    .dfehc-tab-panel p{
        margin:8px 0 12px;
    }
    .dfehc-tab-panel .form-table{
        margin-top:10px;
    }
    .dfehc-tab-panel .form-table th{
        width:260px;
        padding-top:12px;
    }
    .dfehc-tab-panel .form-table td{
        padding-top:10px;
    }
    .dfehc-tab-panel .submit{
        margin:14px 0 12px;
        padding:0;
    }

    @media (max-width:782px){
        .dfehc-tab-panel{padding:14px 12px 6px}
        .dfehc-tab-panel .form-table th{width:auto}
    }
';
wp_add_inline_style( 'dfhcsl-admin-css', $tabs_css );
$adv_css = '
    .dfehc-adv-group{margin:10px 0 14px;border:1px solid #dcdcde;border-radius:12px;background:#fff;overflow:hidden}
    .dfehc-adv-group summary{cursor:pointer;user-select:none;padding:12px 14px;font-weight:700;display:flex;align-items:center;gap:10px}
    .dfehc-adv-group summary::-webkit-details-marker{display:none}
    .dfehc-adv-group summary:after{content:"›";margin-left:auto;transform:rotate(90deg);transition:transform .12s ease;opacity:.6;font-size:16px}
    .dfehc-adv-group[open] summary:after{transform:rotate(-90deg)}
    .dfehc-adv-group .form-table{margin:0}
    .dfehc-adv-group .form-table th{padding-left:14px}
    .dfehc-adv-group .form-table td{padding-right:14px}
';
wp_add_inline_style('dfhcsl-admin-css', $adv_css);

$tabs_js = '
jQuery(function($){
    var $form = $("#dfehc-settings-form");
    if(!$form.length) return;

    var storageKey = "dfehc_active_tab";
    var isSubmitting = false;

    function setDirty(v){ $form.data("dfehcDirty", !!v); }
    function isDirty(){ return $form.data("dfehcDirty") === true; }

    function snapshot(){
        var s = [];
        $form.find(":input[name]").each(function(){
            var $el = $(this), name = $el.attr("name");
            if(!name) return;
            if($el.is(":checkbox,:radio")){
                s.push([name, $el.is(":checked") ? $el.val() : ""]);
            } else {
                s.push([name, $el.val()]);
            }
        });
        return s;
    }

    var initial = snapshot();

    function computeDirty(){
        var now = snapshot();
        if(now.length !== initial.length){ setDirty(true); return; }
        for(var i=0;i<now.length;i++){
            if((initial[i][0] !== now[i][0]) || ((initial[i][1] ?? "") != (now[i][1] ?? ""))){ setDirty(true); return; }
        }
        setDirty(false);
    }

    function activateTab(id){
        $(".dfehc-tabs .nav-tab").removeClass("nav-tab-active");
        $(".dfehc-tabs .nav-tab[data-tab=\\"" + id + "\\"]").addClass("nav-tab-active");
        $(".dfehc-tab-panel").removeClass("is-active");
        $("#" + id).addClass("is-active");
        try { localStorage.setItem(storageKey, id); } catch(e){}
    }

    function getSavedTab(){
        try { return localStorage.getItem(storageKey); } catch(e){}
        return null;
    }

    function clickVisibleSave(){
        if(isSubmitting) return false;
        isSubmitting = true;
        setDirty(false);

        var $panel = $(".dfehc-tab-panel.is-active");
        var $btn = $panel.find("button[type=submit],input[type=submit]").first();

        if($btn.length && $btn[0] && typeof $btn[0].click === "function"){
            $btn[0].click();
            return true;
        }

        if($form[0]){
            try { $form[0].submit(); return true; } catch(e){}
        }

        isSubmitting = false;
        return false;
    }

    $form.on("change input", ":input", function(){ computeDirty(); });

    $form.on("submit", function(){
        if(isSubmitting) return;
        setDirty(false);
        initial = snapshot();
    });

    $(window).on("beforeunload", function(){
        if(isSubmitting) return;
        if(isDirty()) return "You have unsaved changes.";
    });

    $(".dfehc-tabs").on("click", ".nav-tab", function(e){
        e.preventDefault();
        var target = $(this).attr("data-tab");
        if(!target || !$("#"+target).length) return;

        if(isDirty()){
            var saveNow = window.confirm("You have unsaved changes. Click OK to save them now, or Cancel to discard them.");
            if(saveNow){
                clickVisibleSave();
                return;
            } else {
                setDirty(false);
                $form[0].reset();
                initial = snapshot();
            }
        }

        activateTab(target);
    });

    var saved = getSavedTab();
    if(saved && $("#" + saved).length){
        activateTab(saved);
    } else {
        var first = $(".dfehc-tabs .nav-tab").first().attr("data-tab");
        if(first && $("#" + first).length) activateTab(first);
    }
});
';
wp_add_inline_script( 'dfhcsl-admin-js', $tabs_js );


        }

$js = '
jQuery(function($){
    const overlay = $("<div>", { id: "dfehc-loader-overlay" }).addClass("dfehc-loader-overlay")
        .append(
            $("<div>", { class: "dfehc-loader-content" })
                .append($("<div>", { class: "heartbeat-loader" }))
                .append($("<p>").css({ marginTop: "20px", fontSize: "1.2em" }).text("'.esc_js(__('Processing, please wait…','dfehc')).'"))
                .append($("<p>", { id: "dfehc-loader-slow-note" }).css({ marginTop: "12px", fontSize: "1em", opacity: 0.9, display: "none" }).text("'.esc_js(__('This is taking longer than usual. The server might be busy or the task is large. To avoid heavy server load, the process runs gently, which can increase the time needed. Thanks for your patience.','dfehc')).'"))
        );

    $("body").append(overlay);

    let slowTimer = null;
    const startSlowTimer = () => {
        if (slowTimer) clearTimeout(slowTimer);
        $("#dfehc-loader-slow-note").hide();
        slowTimer = setTimeout(() => {
            $("#dfehc-loader-slow-note").fadeIn(200);
        }, 50000);
    };
    const stopSlowTimer = () => {
        if (slowTimer) clearTimeout(slowTimer);
        slowTimer = null;
        $("#dfehc-loader-slow-note").hide();
    };

    $("#dfehc-optimizer-form").on("submit", function(e){
        e.preventDefault();
        let task = $(document.activeElement).val();
        if(!task){
            alert("'.esc_js(__('Could not determine task. Please click a button to optimize.','dfehc')).'");
            return;
        }
        overlay.show();
        startSlowTimer();

        $.post(ajaxurl, {
            action: "dfehc_optimize",
            optimize_function: task,
            _ajax_nonce: "'.wp_create_nonce('dfehc_optimize_action').'"
        })
        .done(() => location.reload())
        .fail(xhr => {
            stopSlowTimer();
            overlay.hide();
            alert(xhr.responseText || "'.esc_js(__('Unexpected error – check the PHP error log.','dfehc')).'");
        });
    });

    $(window).on("beforeunload", function(){
        stopSlowTimer();
    });
});
';
wp_add_inline_script('dfhcsl-admin-js', $js);

    }
}