<?php
session_start();
require 'config.php';

// Only admin users can access this page.
if (!isset($_SESSION['username']) || $_SESSION['admin'] != 1) {
    header("Location: index.php");
    exit();
}

$editSuccess = "";
$deleteSuccess = "";
$error = "";

// Process deletion if a 'delete' GET parameter is provided.
if (isset($_GET['delete'])) {
    $deleteID = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("DELETE FROM tbluser WHERE userID = ?");
        $stmt->execute([$deleteID]);
        $deleteSuccess = "Account deleted successfully.";
    } catch (PDOException $e) {
        $error = "Error deleting account: " . $e->getMessage();
    }
}

// Process editing if the form is submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editAccount'])) {
    $editUserID = intval($_POST['editUserID']);
    $firstname  = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename'] ?? "");
    $lastname   = trim($_POST['lastname']);
    $position   = trim($_POST['position'] ?? "");
    $username   = trim($_POST['username']);
    $password   = trim($_POST['password']);  // If empty, do not update password.
    $adminFlag  = isset($_POST['admin']) ? 1 : 0;
    $officeName = trim($_POST['office']);

    if (empty($firstname) || empty($lastname) || empty($username) || empty($officeName)) {
        $error = "Please fill in all required fields for editing.";
    } else {
        try {
            // Get officeID based on office name.
            $stmtOffice = $pdo->prepare("SELECT officeID FROM officeid WHERE officename = ?");
            $stmtOffice->execute([$officeName]);
            $office = $stmtOffice->fetch();
            if ($office) {
                $officeID = $office['officeID'];
            } else {
                // Insert new office record if it does not exist.
                $stmtInsertOffice = $pdo->prepare("INSERT INTO officeid (officename) VALUES (?)");
                $stmtInsertOffice->execute([$officeName]);
                $officeID = $pdo->lastInsertId();
            }
            // Update the account. If password is provided, update it; otherwise leave it unchanged.
            if (!empty($password)) {
                $stmtEdit = $pdo->prepare("UPDATE tbluser SET firstname = ?, middlename = ?, lastname = ?, position = ?, username = ?, password = ?, admin = ?, officeID = ? WHERE userID = ?");
                $stmtEdit->execute([$firstname, $middlename, $lastname, $position, $username, $password, $adminFlag, $officeID, $editUserID]);
            } else {
                $stmtEdit = $pdo->prepare("UPDATE tbluser SET firstname = ?, middlename = ?, lastname = ?, position = ?, username = ?, admin = ?, officeID = ? WHERE userID = ?");
                $stmtEdit->execute([$firstname, $middlename, $lastname, $position, $username, $adminFlag, $officeID, $editUserID]);
            }
            $editSuccess = "Account updated successfully.";
        } catch(PDOException $e) {
            $error = "Error updating account: " . $e->getMessage();
        }
    }
}

// Retrieve all accounts along with their office names.
$stmt = $pdo->query("SELECT u.*, o.officename FROM tbluser u LEFT JOIN officeid o ON u.officeID = o.officeID ORDER BY u.userID ASC");
$accounts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Accounts - DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/home.css">
  <style>
    /* Styles for the accounts table wrapper */
    .accounts-container {
      max-width: 800px;
      margin: 40px auto;
      background: #fff;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      margin-top: 100px; /* Adjusted to account for header height */
    }
    .accounts-container table {
      width: 100%;
      border-collapse: collapse;
    }
    .accounts-container th, .accounts-container td {
      padding: 10px;
      border: 1px solid #ddd;
      text-align: left;
    }
    .accounts-container th {
      background: #f4f4f4;
    }
    .edit-btn, .delete-btn {
      padding: 6px 10px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      margin-right: 5px;
    }
    .edit-btn {
      background: #0d47a1;
      color: #fff;
    }
    .delete-btn {
      background: #c62828;
      color: #fff;
    }
    /* Modal popup styling for editing an account */
    .modal {
      display: none; /* Hidden by default */
      position: fixed;
      z-index: 1001;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
    }
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
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .close:hover { color: red; }
    form label { display: block; margin-top: 10px; }
    form input[type="text"],
    form input[type="password"] { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
    form button { margin-top: 15px; padding: 10px; width: 100%; border: none; background-color: #0d47a1; color: white; font-weight: bold; border-radius: 4px; cursor: pointer; }
    /* Back button styling */
    .back-btn {
      display: inline-block;
      background-color: #0d47a1;
      color: #fff;
      padding: 8px 12px;
      text-decoration: none;
      border-radius: 4px;
      margin: 10px;
    }
  </style>
</head>
<body>
  <!-- Header (from index.php, with user name and icon) -->
  <div class="header">
  <a href="index.php" style="display: flex; align-items: center; text-decoration: none; color: inherit;">
    <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
    <div class="header-text">
      <div class="title-left">
        SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
      </div>
      <?php if (isset($showTitleRight) && $showTitleRight): ?>
      <div class="title-right">
        Bids and Awards Committee Tracking System
      </div>
      <?php endif; ?>
    </div>
  </a>
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

  <div class="accounts-container">
    <!-- Back Button at top left inside the container -->
    <a href="index.php" class="back-btn" style="position:absolute; top:20px; left:20px;">&#8592; Back</a>
    <h2 style="margin-left:60px;">Manage Accounts</h2>
    <?php 
      if ($deleteSuccess != "") { echo "<p style='color:green;'>" . htmlspecialchars($deleteSuccess) . "</p>"; }
      if ($editSuccess != "") { echo "<p style='color:green;'>" . htmlspecialchars($editSuccess) . "</p>"; }
      if ($error != "") { echo "<p style='color:red;'>" . htmlspecialchars($error) . "</p>"; }
    ?>
    <table>
      <thead>
        <tr>
          <th>User ID</th>
          <th>Name</th>
          <th>Username</th>
          <th>Role</th>
          <th>Office</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $account): ?>
          <tr>
            <td><?php echo htmlspecialchars($account['userID']); ?></td>
            <td><?php echo htmlspecialchars($account['firstname'] . " " . $account['middlename'] . " " . $account['lastname']); ?></td>
            <td><?php echo htmlspecialchars($account['username']); ?></td>
            <td><?php echo ($account['admin'] == 1) ? "Admin" : "User"; ?></td>
            <td><?php echo htmlspecialchars($account['officename'] ?? ""); ?></td>
            <td>
              <button class="edit-btn" data-id="<?php echo $account['userID']; ?>">Edit</button>
              <a class="delete-btn" href="manage_accounts.php?delete=<?php echo $account['userID']; ?>" onclick="return confirm('Are you sure you want to delete this account?');">Delete</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  
  <!-- Edit Account Modal Popup -->
  <div id="editModal" class="modal">
    <div class="modal-content">
      <span class="close" id="editClose">&times;</span>
      <h2>Edit Account</h2>
      <form id="editAccountForm" action="manage_accounts.php" method="post">
        <input type="hidden" name="editUserID" id="editUserID">
        <label for="firstname">First Name*</label>
        <input type="text" name="firstname" id="editFirstname" required>
        
        <label for="middlename">Middle Name</label>
        <input type="text" name="middlename" id="editMiddlename">
        
        <label for="lastname">Last Name*</label>
        <input type="text" name="lastname" id="editLastname" required>
        
        <label for="position">Position</label>
        <input type="text" name="position" id="editPosition">
        
        <label for="username">Username*</label>
        <input type="text" name="username" id="editUsername" required>
        
        <label for="password">Password (leave blank to keep unchanged)</label>
        <input type="password" name="password" id="editPassword">
        
        <label for="office">Office Name*</label>
        <input type="text" name="office" id="editOffice" required>
        
        <label for="admin">Admin</label>
        <input type="checkbox" name="admin" id="editAdmin">
        
        <button type="submit" name="editAccount">Save Changes</button>
      </form>
    </div>
  </div>
  
  <script>
    // Open the Edit Account modal when an Edit button is clicked.
    const editModal = document.getElementById('editModal');
    const editClose = document.getElementById('editClose');
    const editButtons = document.querySelectorAll('.edit-btn');
    
    editButtons.forEach(btn => {
      btn.addEventListener('click', function() {
        const row = this.closest('tr');
        const cells = row.querySelectorAll('td');
        // Extract data from the table row.
        const userID = cells[0].textContent.trim();
        const fullName = cells[1].textContent.trim();
        let nameParts = fullName.split(" ");
        const firstname = nameParts[0] || "";
        const lastname = (nameParts.length > 1) ? nameParts[nameParts.length - 1] : "";
        // For middlename, assume all parts between first and last.
        const middlename = (nameParts.length > 2) ? nameParts.slice(1, nameParts.length - 1).join(" ") : "";
        const username = cells[2].textContent.trim();
        const role = cells[3].textContent.trim();  // "Admin" or "User"
        const office = cells[4].textContent.trim();
        
        // Populate the form fields.
        document.getElementById('editUserID').value = userID;
        document.getElementById('editFirstname').value = firstname;
        document.getElementById('editMiddlename').value = middlename;
        document.getElementById('editLastname').value = lastname;
        document.getElementById('editUsername').value = username;
        document.getElementById('editOffice').value = office;
        document.getElementById('editPassword').value = "";
        // Set admin checkbox based on role.
        document.getElementById('editAdmin').checked = (role === "Admin");
        // Position isn't in the table; leave blank or implement additional logic if needed.
        document.getElementById('editPosition').value = "";
        
        // Display the modal.
        editModal.style.display = 'block';
      });
    });
    
    // Close the modal when clicking on the close button.
    editClose.onclick = function() {
      editModal.style.display = 'none';
    }
    
    // Close the modal when clicking outside the modal content.
    window.onclick = function(event) {
      if (event.target == editModal) {
        editModal.style.display = 'none';
      }
    }
  </script>
</body>
</html>
