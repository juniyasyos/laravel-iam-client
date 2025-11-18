<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Processing Login...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 400px;
        }

        .spinner {
            width: 50px;
            height: 50px;
            margin: 0 auto 20px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        h2 {
            color: #333;
            margin: 0 0 10px;
            font-size: 24px;
        }

        p {
            color: #666;
            margin: 0;
            line-height: 1.6;
        }

        .error {
            background: #fee;
            border: 1px solid #fcc;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            display: none;
        }

        .error h3 {
            color: #c33;
            margin: 0 0 10px;
        }

        .error p {
            color: #c33;
        }

        .error a {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.3s;
        }

        .error a:hover {
            background: #5568d3;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="spinner"></div>
        <h2>🔄 Processing Login</h2>
        <p>Please wait while we complete your authentication...</p>

        <div class="error" id="errorMessage">
            <h3>❌ Authentication Failed</h3>
            <p id="errorText">No access token found in URL</p>
            <a href="{{ route(config('iam.login_route_name', 'login')) }}">Try Again</a>
        </div>
    </div>

    <script>
        (function() {
            console.log('IAM Callback Handler: Starting token extraction...');

            // Try to extract access_token from URL fragment (#access_token=xxx)
            const hash = window.location.hash.substring(1);
            console.log('Hash:', hash);

            const params = new URLSearchParams(hash);
            let accessToken = params.get('access_token');

            // Also try query string as fallback
            if (!accessToken) {
                const queryParams = new URLSearchParams(window.location.search);
                accessToken = queryParams.get('access_token');
                console.log('Token from query string:', accessToken ? 'found' : 'not found');
            }

            if (accessToken) {
                console.log('Access token found, submitting to server...');
                console.log('Token length:', accessToken.length);
                console.log('Token preview:', accessToken.substring(0, 50) + '...');

                // Send token via POST to callback endpoint
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '{{ route("iam.sso.callback") }}';

                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = 'access_token';
                tokenInput.value = accessToken;
                form.appendChild(tokenInput);

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = '{{ csrf_token() }}';
                form.appendChild(csrfInput);

                document.body.appendChild(form);

                // Submit after a small delay to ensure DOM is ready
                setTimeout(() => {
                    console.log('Submitting form...');
                    form.submit();
                }, 100);
            } else {
                console.error('No access token found in URL');
                console.log('Full URL:', window.location.href);

                // Show error message
                document.querySelector('.spinner').style.display = 'none';
                document.querySelector('h2').style.display = 'none';
                document.querySelector('p').style.display = 'none';
                document.getElementById('errorMessage').style.display = 'block';
            }
        })();
    </script>
</body>

</html>