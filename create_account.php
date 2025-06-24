<?php
session_start();
require 'config.php';

// Allow only admin users to create accounts.
if (!isset($_SESSION['username']) || $_SESSION['admin'] != 1) {
    header("Location: index.php");
    exit();
}

$success = false;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve and trim the form values.
    $firstname   = trim($_POST['firstname']);
    $middlename  = trim($_POST['middlename'] ?? "");
    $lastname    = trim($_POST['lastname']);
    $position    = trim($_POST['position'] ?? "");
    $username    = trim($_POST['username']);
    $password    = trim($_POST['password']);  // Plain text for now (not recommended for production)
    $adminFlag   = isset($_POST['admin']) ? 1 : 0;
    $officeName  = trim($_POST['office']);      // New field: Office Name

    // Basic validation—check that required fields are filled.
    if(empty($firstname) || empty($lastname) || empty($username) || empty($password) || empty($officeName)){
       $error = "Please fill in all required fields.";
    } else {
        try {
            // First, look up the office by name in the officeid table.
            $stmtOffice = $pdo->prepare("SELECT officeID FROM officeid WHERE officename = ?");
            $stmtOffice->execute([$officeName]);
            $office = $stmtOffice->fetch();
            
            if ($office) {
                // Office exists: get the officeID.
                $officeID = $office['officeID'];
            } else {
                // Insert new office record.
                $stmtInsertOffice = $pdo->prepare("INSERT INTO officeid (officename) VALUES (?)");
                $stmtInsertOffice->execute([$officeName]);
                $officeID = $pdo->lastInsertId();
            }
            
            // Now insert the new user into tbluser.
            $stmtUser = $pdo->prepare("INSERT INTO tbluser (firstname, middlename, lastname, position, username, password, admin, officeID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtUser->execute([$firstname, $middlename, $lastname, $position, $username, $password, $adminFlag, $officeID]);
            
            $success = true;
            // Retrieve the newly created account details using the auto-generated userID.
            $newAccountID = $pdo->lastInsertId();
            $stmt2 = $pdo->prepare("SELECT u.*, o.officename FROM tbluser u LEFT JOIN officeid o ON u.officeID = o.officeID WHERE u.userID = ?");
            $stmt2->execute([$newAccountID]);
            $newAccount = $stmt2->fetch();
            
        } catch (PDOException $e) {
            $error = "Error creating account: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create Account - DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/home.css">
  <style>
    /* Modal container styling */
    .modal {
      display: block; /* Always shown on this page */
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4); /* Semi-transparent overlay */
    }
    /* Modal content styling */
    .modal-content {
      background-color: #fefefe;
      margin: 10% auto;
      padding: 20px;
      border: 1px solid #888;
      width: 90%;
      max-width: 500px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .close:hover {
      color: black;
    }
    form label {
      display: block;
      margin-top: 10px;
    }
    form input[type="text"],
    form input[type="password"] {
      width: 100%;
      padding: 8px;
      margin-top: 4px;
      box-sizing: border-box;
    }
    form button {
      margin-top: 15px;
      padding: 10px;
      width: 100%;
      border: none;
      background-color: #0d47a1;
      color: white;
      font-weight: bold;
      border-radius: 4px;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <!-- Header (from index.php, with user name and icon) -->
  <div class="header">
    <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
    <div class="header-text">
      <div class="title-left">
        SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
      </div>
    </div>
    <div class="user-menu">
      <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
      <div class="dropdown">
        <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon">
        <div class="dropdown-content">
          <a href="logout.php" id="logoutBtn">Log out</a>
        </div>
      </div>
    </div>
  </div>
  <!-- Modal Container -->
  <div class="modal">
    <div class="modal-content">
      <!-- Close button: clicking it redirects back to the dashboard -->
      <span class="close" onclick="window.location.href='index.php'">&times;</span>
      <h2>Create Account</h2>
      
      <?php if ($error != ""): ?>
         <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>
      
      <?php if (!$success): ?>
      <!-- Create Account Form -->
      <form action="create_account.php" method="post">
         <!-- Removed manual User ID input; auto-increment handles it. -->
         
         <label for="firstname">First Name*</label>
         <input type="text" name="firstname" id="firstname" required>
         
         <label for="middlename">Middle Name</label>
         <input type="text" name="middlename" id="middlename">
         
         <label for="lastname">Last Name*</label>
         <input type="text" name="lastname" id="lastname" required>
         
         <label for="position">Position</label>
         <input type="text" name="position" id="position">
         
         <label for="username">Username*</label>
         <input type="text" name="username" id="username" required>
         
         <label for="password">Password*</label>
         <input type="password" name="password" id="password" required>
         
         <!-- New field: Office Name instead of Office ID -->
         <label for="office">Office Name*</label>
         <input type="text" name="office" id="office" required>
         
         <label for="admin">Admin</label>
         <input type="checkbox" name="admin" id="admin">
         
         <button type="submit">Create Account</button>
      </form>
      <?php else: ?>
         <!-- Confirmation Popup Content -->
         <h3>Account Created Successfully!</h3>
         <p><strong>User ID:</strong> <?php echo htmlspecialchars($newAccount['userID']); ?></p>
         <p><strong>Username:</strong> <?php echo htmlspecialchars($newAccount['username']); ?></p>
         <p><strong>Name:</strong> <?php echo htmlspecialchars($newAccount['firstname'] . " " . $newAccount['middlename'] . " " . $newAccount['lastname']); ?></p>
         <p><strong>Office:</strong> <?php echo htmlspecialchars($newAccount['officename']); ?></p>
         <p><strong>Role:</strong> <?php echo ($newAccount['admin'] == 1) ? "Admin" : "User"; ?></p>
         <button onclick="window.location.href='manage_accounts.php'">Proceed to Manage Accounts</button>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
