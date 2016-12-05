{if eq( ezini( 'DebugSettings', 'DebugOutput', 'site.ini' ), 'enabled' )}
<link rel="stylesheet" type="text/css" media="screen" href={'stylesheets/debug-toolbar.css'|ezdesign} />
<div class="debug-output debug-hide {if ne( ezini( 'DebugSettings', 'DebugOutput', 'site.ini' ), 'enabled' )}debug-disabled{/if}">
    <div class="debug-button-show debug-button"><a href="#">&lt;&lt;</a></div> 
   <div class="debug-button-hide debug-button"><a href="#">Hide &gt;&gt;</a> <button id="debug-log-console" title="Sends the debug log to webbrower console">Log to console</button></div>
    <div class="debug-report">
    <!--DEBUG_REPORT-->
    </div>
<script>
{literal}
/* Prevent scoping vars in global scope. */
(function () {
    // wrapper around setting / getting cookie.
    var CookieHandler = {
        setCookie: function (key, value) {
            var expires = new Date();
            expires.setTime(expires.getTime() + (1 * 24 * 60 * 60 * 1000));
            document.cookie = key + '=' + value + ';expires=' + expires.toUTCString();
        },

        getCookie: function (key) {
            var keyValue = document.cookie.match('(^|;) ?' + key + '=([^;]*)(;|$)');
            return keyValue ? keyValue[2] : null;
        }
    };




    $('.debug-output .debug-button-show').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).parents('.debug-output').removeClass('debug-hide').addClass('debug-show');
        CookieHandler.setCookie('aplia-debug-toolbar', 'show');
        return false;
    });
    $('.debug-output .debug-button-hide').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).parents('.debug-output').removeClass('debug-show').addClass('debug-hide');
        CookieHandler.setCookie('aplia-debug-toolbar', 'hide');
    });

    $debugTable = $('.debug-output .debug-report > div > table').first();
    var log = [],
        hasErrors = false,
        hasWarnings = false,
        hasDebugs = false,
        hasNotices = false;
    $debugTable.find('tr.timing, tr.notice, tr.warning, tr.error, tr.debug').each(function () {
        var $tr = $(this),
            $trText = $tr.next(),
            title = $tr.find('td').first().text(),
            text = $trText.text(),
            type = 'notice';
        if ($tr.hasClass('timing')) {
            type = 'timing';
        } else if ($tr.hasClass('notice')) {
            type = 'notice';
            hasNotices = true;
        } else if ($tr.hasClass('warning')) {
            type = 'warning';
            hasWarnings = true;
        } else if ($tr.hasClass('error')) {
            type = 'error';
            hasErrors = true;
        } else if ($tr.hasClass('debug')) {
            type = 'debug';
            hasDebugs = true;
        }
        log.push([title, text, type]);
    });

    // Build new log with only divs
    $debugTable.detach();
    $newLog = $('<div>').addClass('debug-log');
    $newLog.insertAfter('.debug-output .debug-report > div > h2');
    for (var i = 0; i < log.length; ++i) {
        var $logItem = $('<div>').addClass('debug-log-item').append(
            $('<h3>').text(log[i][0]),
            $('<pre>').text(log[i][1])
        ).addClass('debug-level-' + log[i][2]);
        $newLog.append($logItem);
    }

    function sendLogToConsole(showNotice) {
        for (var i = 0; i < log.length; ++i) {
            try {
                var logItem = log[i],
                    title = logItem[0],
                    text = logItem[1],
                    type = logItem[2];
                if (type == 'error') {
                    console.error("%s: %s", title, text);
                } else if (type == 'warning') {
                    console.error("%s: %s", title, text);
                } else if (type == 'debug' && showNotice) {
                    console.log("%s: %s", title, text);
                } else if (type == 'notice' && showNotice) {
                    console.info("%s: %s", title, text);
                }
            } catch($e) {
                console.exception($e);
            }
        };
    }
    if (console) {
        sendLogToConsole();
    }

    $('#debug-log-console').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (console) {
            sendLogToConsole();
        }
    });

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
        if (hasErrors)
            $debugOutput.addClass('debug-error');
        else if (hasWarnings)
            $debugOutput.addClass('debug-warning');
        else if (hasDebugs)
            $debugOutput.addClass('debug-debug');
        else if (hasNotices)
            $debugOutput.addClass('debug-notice');
    }


    // Set initial state.
    var state = CookieHandler.getCookie('aplia-debug-toolbar');
    if (state) {
        if (state==='show') {
            $('.debug-output').removeClass('debug-hide').addClass('debug-show');
        }
    }
})();
</script>
{/literal}
</div>
{/if}
