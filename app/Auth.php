<?php
declare(strict_types=1);

final class Auth
{
    public static function ensureUserSchema(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('member','admin','superadmin') NOT NULL DEFAULT 'member',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_users_role (role),
            CONSTRAINT fk_users_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try {
            $firstAdminId=(int)($pdo->query('SELECT MIN(id) FROM admins')->fetchColumn()?:0);
            $admins=$pdo->query('SELECT id,name,email,password_hash,is_active,last_login_at FROM admins')->fetchAll();
            $insert=$pdo->prepare('INSERT IGNORE INTO users (name,email,password_hash,role,is_active,last_login_at) VALUES (:name,:email,:password_hash,:role,:is_active,:last_login_at)');
            foreach($admins as$admin){$insert->execute(['name'=>$admin['name'],'email'=>strtolower((string)$admin['email']),'password_hash'=>$admin['password_hash'],'role'=>((int)$admin['id']===$firstAdminId?'superadmin':'admin'),'is_active'=>(int)$admin['is_active'],'last_login_at'=>$admin['last_login_at']]);}
        } catch(Throwable) {}
        $done=true;
    }

    public static function check(): bool { return !empty($_SESSION['user_id']) || !empty($_SESSION['admin_id']); }
    public static function role(): string { if(!empty($_SESSION['user_role']))return(string)$_SESSION['user_role'];return!empty($_SESSION['admin_id'])?'admin':''; }
    public static function isAdmin(): bool { return in_array(self::role(),['admin','superadmin'],true); }
    public static function isSuperAdmin(): bool { return self::role()==='superadmin'; }
    public static function memberId(): int { return(int)($_SESSION['member_id']??0); }
    public static function requireAuthenticated(): void { if(!self::check()){header('Location: /login.php');exit;} }
    public static function requireLogin(): void { self::requireAuthenticated();if(self::isAdmin())return;$script=basename((string)($_SERVER['SCRIPT_NAME']??''));if($script==='badge.php'&&self::role()==='member'&&(int)($_GET['id']??0)===self::memberId()&&self::memberId()>0)return;header('Location: /profile.php');exit; }
    public static function requireSuperAdmin(): void { self::requireAuthenticated();if(!self::isSuperAdmin()){http_response_code(403);exit('Geen toegang.');} }
    public static function homePath(): string { return self::isAdmin()?'/index.php':'/profile.php'; }

    public static function attempt(string $email,string $password): bool
    {
        self::ensureUserSchema();
        $pdo=Database::connection();
        $email=strtolower(trim($email));
        $stmt=$pdo->prepare('SELECT id,member_id,email,password_hash,name,role,is_active FROM users WHERE LOWER(email)=:email LIMIT 1');
        $stmt->execute(['email'=>$email]);
        $user=$stmt->fetch();

        // Als het e-mailadres op de ledenfiche gewijzigd werd maar de login nog het oude
        // adres bevat, laat het nieuwe ledenadres meteen als login werken en synchroniseer users.
        if(!$user){
            $stmt=$pdo->prepare('SELECT u.id,u.member_id,u.email,u.password_hash,u.name,u.role,u.is_active FROM users u INNER JOIN members m ON m.id=u.member_id WHERE u.role="member" AND LOWER(m.email)=:email LIMIT 1');
            $stmt->execute(['email'=>$email]);
            $user=$stmt->fetch();
            if($user){
                try{
                    $pdo->prepare('UPDATE users SET email=:email WHERE id=:id')->execute(['email'=>$email,'id'=>$user['id']]);
                    $user['email']=$email;
                }catch(Throwable){
                    return false;
                }
            }
        }

        if(!$user||!(bool)$user['is_active']||!password_verify($password,$user['password_hash']))return false;
        session_regenerate_id(true);$_SESSION['user_id']=(int)$user['id'];$_SESSION['user_name']=(string)$user['name'];$_SESSION['user_email']=(string)$user['email'];$_SESSION['user_role']=(string)$user['role'];$_SESSION['member_id']=(int)($user['member_id']??0);
        if(in_array($user['role'],['admin','superadmin'],true)){$_SESSION['admin_id']=(int)$user['id'];$_SESSION['admin_name']=(string)$user['name'];$_SESSION['admin_email']=(string)$user['email'];}else unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['admin_email']);
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute(['id'=>$user['id']]);return true;
    }

    public static function logout(): void
    {
        $_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain']??'',$params['secure'],$params['httponly']);}session_destroy();
    }
}
