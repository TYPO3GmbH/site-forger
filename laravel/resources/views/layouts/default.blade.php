<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>[{{ $context }}] @yield('title') :: ForgerNG</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{{ URL::asset('css/app.css') }}" />
</head>
<body>
<nav class="navbar navbar-default navbar-fixed-top">
    <div class="container-fluid">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
                <span class="sr-only">Toggle navigation</span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="/">TYPO3 FORGER :: @yield('title')</a>
        </div>
        <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
            @include('partials.menu')
        </div>
    </div>
</nav>
<div class="container">
    <div class="col-sm-12">
        <h4>@TODO: FlashMessages</h4>
    </div>
</div>
<div class="container-fluid">
    @yield('content')
</div>
<div class="container-fluid">
    <div class="row">
        <div class="col-sm-12">
            <div class="panel panel-default">
                <div class="panel-body">
                    Found a bug? Got a feature you want in? <a href="https://github.com/wmdbsystems/WMDB.Forger" target="_blank">Tell us on GitHub</a>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- script type="application/javascript" src="{{ URL::asset('js/WMDB.Forger.min.js') }}"></script>
<script type="application/javascript" src="{{ URL::asset('js/register-element.js' )}}"></script>
<script type="application/javascript" src="{{ URL::asset('js/time-elements.js' )}}"></script -->
<script type="text/javascript" src="{{ URL::asset('js/jquery.min.js') }}"></script>
<script type="text/javascript" src="{{ URL::asset('js/bootstrap.min.js') }}"></script>
@section('javascript')
    This is the master sidebar.
@show

@if (count($context) === 'Production')
    <!-- Piwik -->
    <script type="text/javascript">
        var _paq = _paq || [];
        _paq.push(['trackPageView']);
        _paq.push(['enableLinkTracking']);
        (function() {
            var u="//analytics.wmdb.de/";
            _paq.push(['setTrackerUrl', u+'piwik.php']);
            _paq.push(['setSiteId', 7]);
            var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
            g.type='text/javascript'; g.async=true; g.defer=true; g.src=u+'piwik.js'; s.parentNode.insertBefore(g,s);
        })();
    </script>
    <noscript><p><img src="//analytics.wmdb.de/piwik.php?idsite=7" style="border:0;" alt="" /></p></noscript>
    <!-- End Piwik Code -->
@endif
</body>
</html>