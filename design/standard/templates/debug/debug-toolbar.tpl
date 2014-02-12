{if eq( ezini( 'DebugSettings', 'DebugOutput', 'site.ini' ), 'enabled' )}
<div class="debug-output debug-hide {if ne( ezini( 'DebugSettings', 'DebugOutput', 'site.ini' ), 'enabled' )}debug-disabled{/if}">
    <div class="debug-button-show debug-button"><a href="#">&lt;&lt;</a></div> 
   <div class="debug-button-hide debug-button"><a href="#">Hide &gt;&gt;</a></div>
    <div class="debug-report">
    <!--DEBUG_REPORT-->
    </div>
<script>
{literal}
$('.debug-output .debug-button-show').on('click', function (e) {
  $(this).parents('.debug-output').removeClass('debug-hide').addClass('debug-show');
});
$('.debug-output .debug-button-hide').on('click', function (e) {
  $(this).parents('.debug-output').removeClass('debug-show').addClass('debug-hide');
});
$(document).ready(function () {
    var $debugOutput = $('.debug-output'),
        $xdebugError = $('table.xdebug-error,table.xdebug-warning').parents('font');
    // if ($xdebugError)
    // {
    //     $('.debug-output .debug-report').prepend($xdebugError.detach());
    //     $debugOutput.removeClass('debug-disabled');
    // }
    // Hide the debug output if the report is missing
    if ( $('.debug-output .debug-report').children().length == 0 )
    {
        $debugOutput.addClass('debug-disabled');
    }
    else
    {
        var $errorEls = $('.debug-output .debug-report td.debugheader span');
        // Determine if there are errors, warnings, debugs or notice and set class
        if ( $errorEls .filter(function (i) { return /error/i.test($(this).text()); }) .length )
            $debugOutput.addClass('debug-error');
        else if ( $errorEls.filter(function (i) { return /warning/i.test($(this).text()); }).length )
            $debugOutput.addClass('debug-warning');
        else if ( $errorEls.filter(function (i) { return /debug/i.test($(this).text()); }).length )
            $debugOutput.addClass('debug-debug');
        else if ( $errorEls.filter(function (i) { return /notice/i.test($(this).text()); }).length )
            $debugOutput.addClass('debug-notice');
    }
});
</script>
{/literal}
</div>
{/if}
