<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('branches.manage');

$id = (int) ($_GET['id'] ?? 0);

if (!$id) {
    header('Location: ' . APP_URL . '/branches/');
    exit;
}


$stmt = $pdo->prepare("
    SELECT *
    FROM branches
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$branch = $stmt->fetch();


if (!$branch) {
    header('Location: ' . APP_URL . '/branches/');
    exit;
}


$error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    if (!verify_csrf($_POST['csrf_token'] ?? '')) {


        $error = 'Invalid security token. Please refresh and try again.';


    } else {


        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $status = $_POST['status'] ?? 'ACTIVE';



        if ($code === '' || $name === '') {


            $error = 'Branch code and branch name are required.';


        } else {


            $check = $pdo->prepare("
                SELECT id
                FROM branches
                WHERE code = ?
                AND id != ?
                LIMIT 1
            ");


            $check->execute([
                $code,
                $id
            ]);



            if ($check->fetch()) {


                $error = 'Another branch already uses this code.';


            } else {


                $update = $pdo->prepare("
                    UPDATE branches
                    SET
                        code = ?,
                        name = ?,
                        location = ?,
                        phone = ?,
                        email = ?,
                        status = ?
                    WHERE id = ?
                ");



                $update->execute([

                    $code,
                    $name,
                    $location,
                    $phone,
                    $email,
                    $status,
                    $id

                ]);



                header(
                    'Location: ' . APP_URL . '/branches/'
                );

                exit;

            }

        }

    }

}



include __DIR__ . '/../includes/header.php';

?>


<style>

.branch-wrapper {

    max-width:950px;

}



.branch-card {

    background:#ffffff;
    border-radius:18px;
    padding:35px;
    border:1px solid #eeeeee;
    box-shadow:0 10px 30px rgba(0,0,0,.06);

}



.branch-title {

    margin-bottom:25px;

}



.branch-title h3 {

    color:#64131f;
    font-size:20px;
    margin-bottom:6px;

}



.branch-title p {

    color:#777;
    font-size:14px;

}



.branch-grid {

    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:22px;

}



.branch-group label {

    display:block;
    font-size:13px;
    font-weight:700;
    color:#4d0e17;
    margin-bottom:8px;

}



.branch-group input,
.branch-group select {

    width:100%;
    height:46px;
    padding:0 14px;
    border-radius:10px;
    border:1px solid #ddd;
    font-size:14px;
    outline:none;

}



.branch-group input:focus,
.branch-group select:focus {

    border-color:#f2c94c;
    box-shadow:0 0 0 3px rgba(242,201,76,.25);

}



.branch-actions {

    margin-top:30px;
    padding-top:20px;
    border-top:1px solid #eeeeee;
    display:flex;
    justify-content:flex-end;

}



.branch-save {

    background:#64131f;
    color:#ffffff;
    border:none;
    border-radius:10px;
    padding:12px 25px;
    font-weight:700;
    cursor:pointer;
    display:flex;
    align-items:center;
    gap:8px;

}



.branch-save:hover {

    background:#8b1e2d;

}



.branch-back {

    background:#f2c94c;
    color:#4d0e17;
    font-weight:700;

}



@media(max-width:768px){

    .branch-grid{

        grid-template-columns:1fr;

    }


    .branch-card{

        padding:20px;

    }

}


</style>



<div class="page-header">


<div>

<p class="page-kicker">
Haven Mart
</p>


<h2>
Edit Branch
</h2>


<p>
Update branch information and operational status.
</p>


</div>



<a href="<?= APP_URL ?>/branches/" class="button branch-back">

<i class="fas fa-arrow-left"></i>

Back

</a>


</div>




<div class="branch-wrapper">


<div class="branch-card">



<div class="branch-title">

<h3>

<i class="fas fa-store"></i>

Branch Information

</h3>


<p>
Modify details for this Haven Mart location.
</p>


</div>



<?php if ($error): ?>


<div class="alert alert-error">

<i class="fas fa-circle-exclamation"></i>

<?= e($error) ?>

</div>


<?php endif; ?>



<form method="POST">


<?= csrf_field() ?>



<div class="branch-grid">



<div class="branch-group">

<label>
Branch Code *
</label>


<input
type="text"
name="code"
value="<?= e($branch['code']) ?>"
required
>


</div>




<div class="branch-group">

<label>
Branch Name *
</label>


<input
type="text"
name="name"
value="<?= e($branch['name']) ?>"
required
>


</div>




<div class="branch-group">

<label>
Location
</label>


<input
type="text"
name="location"
value="<?= e($branch['location']) ?>"
>


</div>




<div class="branch-group">

<label>
Phone
</label>


<input
type="text"
name="phone"
value="<?= e($branch['phone']) ?>"
>


</div>




<div class="branch-group">

<label>
Email
</label>


<input
type="email"
name="email"
value="<?= e($branch['email']) ?>"
>


</div>




<div class="branch-group">

<label>
Status
</label>


<select name="status">


<option value="ACTIVE"
<?= $branch['status'] === 'ACTIVE' ? 'selected' : '' ?>>
Active
</option>


<option value="INACTIVE"
<?= $branch['status'] === 'INACTIVE' ? 'selected' : '' ?>>
Inactive
</option>


</select>


</div>



</div>




<div class="branch-actions">


<button type="submit" class="branch-save">


<i class="fas fa-save"></i>

Update Branch


</button>


</div>



</form>


</div>


</div>



<?php include __DIR__ . '/../includes/footer.php'; ?>