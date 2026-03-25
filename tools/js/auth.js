$('#login_form').submit(function(){
    url = $(this).attr('action');
    // alert(url);
    email = $('#email').val();
    password = $('#password').val();

    //Ajax
    $.post(url, {'email':email, 'password':password}, function(fb){
        //alert(fb);
        if(fb.match('1'))
            {
                window.location.href = BASE_URL  + 'index.php/admin';
            }
            else if(fb.match(2))
            {
                window.location.href = BASE_URL  + 'index.php/student_account';
            }
            else 
            {
                alert('Username and Password is Not Match');
            }
    });

    return false;

});