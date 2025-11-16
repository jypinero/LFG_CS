<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>OTP Code</title>
	</head>
	<body>
		<p>Your one-time password is:</p>
		<h2 style="letter-spacing:4px">{{ $code }}</h2>
		<p>This code expires in {{ $minutes }} minutes.</p>
		<p>If you did not request this, you can ignore this email.</p>
	</body>
	</html>


