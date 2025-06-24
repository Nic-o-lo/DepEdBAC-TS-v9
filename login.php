<?php
// Start the session and include the database connection
session_start();
require 'config.php';

// Process the login when the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Query the database for a user with the provided username
    $stmt = $pdo->prepare("SELECT * FROM tblUser WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    // For development: check plain text password (later replace with hashed password verification)
    if ($user && $password === $user['password']) {
        // Save user details in session for later use
        $_SESSION['userID']   = $user['userID'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['admin']    = $user['admin']; // 1 means admin, 0 means regular user
        
        // Redirect to the landing page after successful login
        header("Location: index.php");
        exit();
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - DepEd BAC Tracking System</title>
  <!-- Point to your CSS file in the assets folder -->
  <link rel="stylesheet" href="assets/css/home.css">
</head>
<body class="home-bg">

  <div class="header">
    <!-- Update image paths if needed (assuming images are in assets/images/) -->
    <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
    <div class="header-text">
      <div class="title-left">
        SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
      </div>
      <div class="title-right">
        Bids and Awards Committee Tracking System
      </div>
    </div>
  </div>

  <div class="login-flex-wrapper">
    <div class="login-container">
      <div class="login-box">
        <img src="assets/images/DepEd_Name_Logo.png" alt="DepEd" class="login-logo">
        <!-- Display error messages from PHP -->
        <?php if (isset($error)): ?>
          <p style="color:red; font-weight:bold;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <!-- Form updated to use POST and include name attributes -->
        <form id="loginForm" action="login.php" method="post">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" placeholder="Enter your username" required>

          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="Enter your password" required>

          <button type="submit">Sign In</button>
        </form>
      </div>
    </div>
    <img src="assets/images/DepEd_Logo.png" alt="DepEd Logo" class="side-logo">
  </div>

  <!-- Remove or comment out login.js since server side handles authentication -->
  <!-- <script src="assets/js/login.js"></script> -->
</body>
</html>
