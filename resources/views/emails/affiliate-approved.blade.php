<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Affiliate Account Approved</title>
</head>

<body>

    <h2>Congratulations, {{ $affiliate->name }}!</h2>

    <p>
        Your affiliate application has been reviewed and
        <strong>approved</strong>.
    </p>

    <p>
        You can now login to your affiliate account using
        the credentials below:
    </p>

    <table cellpadding="8">
        <tr>
            <td><strong>Email:</strong></td>
            <td>{{ $affiliate->email }}</td>
        </tr>

        <tr>
            <td><strong>Password:</strong></td>
            <td>{{ $password }}</td>
        </tr>
    </table>

    <h3>Your Affiliate Referral Link</h3>

    <p>
        Share this link with your referrals:
    </p>

    <p>
        <a href="{{ $referralUrl }}">
            {{ $referralUrl }}
        </a>
    </p>

    <p>
        Please keep your login credentials secure.
    </p>

    <p>
        Regards,<br>
        {{ config('app.name') }}
    </p>

</body>
</html>