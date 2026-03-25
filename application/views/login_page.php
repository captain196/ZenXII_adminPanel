<div class="content-wrapper">
    <div class="container">
        <div class="login-container">
            <h3 class="header">School Login</h3>

            <form id="loginForm">
                <div class="mb-3">
                    <label for="schoolId" class="form-label">School ID</label>
                    <input type="text" class="form-control" id="schoolId" placeholder="School ID" required>
                </div>
                <!-- <div class="mb-3">
                    <label for="session" class="form-label">Session</label>
                    <select class="form-control" id="session" required>
                        <option value="">Select Session</option>
                    </select>
                </div> -->
                <div class="mb-3">
                    <label for="userId" class="form-label">User ID</label>
                    <input type="text" class="form-control" id="userId" placeholder="User Id" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" placeholder="Password" required>
                </div>
                <button type="submit" class="btn btn-success center-block w-100" style="margin-top: 20px;">Login</button>
            </form>

        </div>
    </div>
</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script>
    $(document).ready(function() {
        $("form").submit(function(e) {
            e.preventDefault();

            var schoolId = $("#schoolId").val();
            var userId = $("#userId").val();
            var password = $("#password").val();

            $.ajax({
                url: "<?= base_url('login/login_pagex') ?>",
                type: "POST",
                data: {
                    schoolId: schoolId,
                    userId: userId,
                    password: password
                },
                dataType: "json",
                success: function(response) {
                    if (response.status) {
                        window.location.href = response.redirect;
                    } else {
                        alert(response.message);
                    }
                }
            });
        });
    });
</script>





<style>
    .header {
        background-color: #1b7fcc;
        color: white;
        padding: 15px 20px;
        font-size: 20px;
        display: flex;
        align-items: center;

        justify-content: center;
        /* Centers the content horizontally */
        text-align: center;
    }

    .content-wrapper {
        padding: 20px;
        background-color: #f8f9fa;
    }

    body {
        background-color: #f8f9fa;
    }

    .login-container {
        max-width: 400px;
        margin: 80px auto;
        padding: 30px;
        background: #e1d0d0;
        border-radius: 10px;
        box-shadow: 0px 4px 8px rgba(155, 53, 53, 0.1);
    }

    .login-container h3 {
        text-align: center;
        margin-bottom: 20px;
    }
</style>