 <?php
/******************************************************************************

PHP Code Pastebin
=================

This tool is designed to enable collaborative code review via the #php IRC
channel. Inspired by www.parseerror.com/paste, but more streamlined and
capable of allowing collabation via IRC by allowing easy modification of
posted code. Another benefit is short urls - http://pastebin.com/333

You can do whatever you want with this code, but if you have fixes or
feature suggestions, please send them to paul@elphin.com.


History
=======
v0.1 - 04-Sep-02 - First version


ToDo
====
- highlight diffs between code revisions
- make the tab key work in the textarea (suggested by Dmitri)
- hyperlink php keywords in the source
- add a 'view full screen' option

Requirements:
=============
- PHP 4.1 or higher (works with register-globals off)
- MySQL database with following table

    create table pastebin
    (
        pid int auto_increment not null,
        poster varchar(64),
        posted datetime,
        code text,
        parent_pid int default '0',
        primary key(pid)
    );

- For short url generation, Apache with mod_rewrite available. Something
  like the following configuration options should be used

    RewriteEngine on
    RewriteRule /([0-9]+) /pastebin.php?show=$1

  If mod_rewrite is not available, modify the shorturl() function to
  generate 'normal' urls.

******************************************************************************/



///////////////////////////////////////////////////////////////////////////////
// basic DB class with just the stuff we need
//
class DB
{

    var $dbhost="*****";
    var $dbname="*****";
    var $dbuser="*****";
    var $dbpass="*****";
    
    var $dblink;
    var $dbresult;
    var $row;
    
    function DB($sql="")
    {
        $this->dblink=mysql_pconnect($this->dbhost,$this->dbuser,$this->dbpass)
            or die("Unable to connect to database");
    
        mysql_select_db($this->dbname, $this->dblink)
            or die("Unable to select database {$this->dbname}");
    
        if (strlen($sql))
            $this->query($sql);
    }
    
    function query($sql)
    {
        $this->dbresult=mysql_query($sql, $this->dblink);
        if (!$this->dbresult)
        {
            die("Query failure: ".mysql_error()."<br />$sql");
        }
        return $this->dbresult;
    }
    
    function get_insert_id()
    {
        return mysql_insert_id($this->dblink);
    }
        
    
    function next_record()
    {
        $this->row=mysql_fetch_array($this->dbresult);
        return $this->row!=FALSE;
    }
    
    function f($field)
    {
        return $this->row[$field];
    }
}




///////////////////////////////////////////////////////////////////////////////
// global functions
//
function smart_addslashes($str) 
{
    if (get_magic_quotes_gpc()) 
        return $str;
    else
        return addslashes($str);
}


function shorturl($id)
{
    return "http://$_SERVER[HTTP_HOST]/$id";
}

function highlight_syntax($php)
{
    ob_start();
    @highlight_string($php);
    $code = ob_get_contents();
    ob_end_clean();
    return $code;
}

///////////////////////////////////////////////////////////////////////////////
// global variables
//
$db=new DB;    


//because we use mod_rewrite to create very short urls,
//we can't rely on PHP_THIS to get our script name
$this_script="/pastebin.php";

//what's the maximum number of posts we want to keep?
$max_posts=50;


///////////////////////////////////////////////////////////////////////////////
// garbage collection
//
// 5% chance of trigging garbage collection - remove the oldest posts
// leaving most recent 50 posts remaining

if(rand()%100 < 5)
{
    $db->query("select count(*) as cnt from pastebin");    
    if($db->next_record())
    {
        $delete_count=$db->f("cnt")-$max_posts;
        if ($delete_count>0)
        {
            //build a one-shot statement to delete old posts
            $sql="delete from pastebin where pid in (";
            $sep="";
            $db->query("select * from pastebin order by posted asc limit $delete_count");
            while ($db->next_record())
            {
                $sql.=$sep.$db->f("pid");
                $sep=",";
            }
            $sql.=")";
            
            //delete extra posts
            $db->query($sql);
        }
    }
}


///////////////////////////////////////////////////////////////////////////////
// process new posting
//
$errors=array();
if (isset($_POST["paste"]))
{
    
    //set/clear the persistName cookie
    if ($_POST["remember"])
    {
        //set cookie if not set
        if (!isset($_COOKIE["persistName"]))
            setcookie ("persistName", $_POST["poster"], time()+3600*24*365);  
    }
    else
    {
        //clear cookie if set
        if (isset($_COOKIE["persistName"]))
            setcookie ("persistName", "", 0);
    }
    
    if (strlen($_POST["code"]))
    {
        $poster=$_POST["poster"];
        if (strlen($poster)==0)
            $poster="Anonymous";
        
        //wrap the code at 80 columns - this is how it looked in the textarea
        //and ensures we keep a nice layout on the page
        $code = wordwrap($_POST["code"], 80, "\n", 1);
        
        //ensure code has begin and end tags somewhere
        $code = trim($code);
        if (strpos($code, '<?') === false)
            $code = "<?php\n".$code;
        if (strpos($code, '?>') === false)
            $code .= "\n?>";

        $parent_pid=0;
        if (isset($_POST["parent_pid"]))
            $parent_pid=intval($_POST["parent_pid"]);
            
        $sql="insert into pastebin (poster, posted, code, parent_pid) values (".
            "'".smart_addslashes($poster)."',".
            "now(),".
            "'".smart_addslashes($code)."',".
            "$parent_pid".
            ");";
            
        $db->query($sql);    
        $id=$db->get_insert_id();
        
        //now redirect, making refresh easier
        header("Location:".shorturl($id));
    }
    else
    {
        $errors[]="No code specified";
    }
}


///////////////////////////////////////////////////////////////////////////////
// view source code
//
if (isset($_GET["showsource"]))
{
    switch($_GET["showsource"])
    {
        case "php":    
            $script=$_SERVER["SCRIPT_FILENAME"];
            $fp=fopen($script,"r");
            $contents=fread($fp, filesize($script));
            fclose($fp);

            //remove passwords
            $contents=preg_replace('{(var \\$db....)=".*?"}', 
                '$1="*****"', $contents);
            $contents=highlight_syntax($contents);

            //hyperlink css
            $contents=str_replace('pastebin.css', 
                '<a href="pastebin.php?showsource=css">pastebin.css</a>', 
                $contents);
                
            echo $contents;
            break;
        case "css":    
            $css=str_replace(".php", ".css", $_SERVER["SCRIPT_FILENAME"]);
            $fp=fopen($css,"r");
            $contents=fread($fp, filesize($css));
            fclose($fp);

            echo "<pre>".$contents."</pre>";
            break;
    }
    
    exit;
}


///////////////////////////////////////////////////////////////////////////////
// HTML page output
//

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<title>php pastebin - collaborative irc debugging</title>
<link rel="stylesheet" type="text/css" media="screen" href="pastebin.css" />
</head>


<body>

<p style="display: none;">This site is developed to XHTML and CSS2 W3C standards.  
If you see this paragraph, your browser does not support those standards and you
need to upgrade.  Visit <a href="http://www.webstandards.org/upgrade/" target="_blank">WaSP</a>
for a variety of options.</p>

<div id="titlebar">php pastebin - collaborative irc debugging
<a href="<?php echo $this_script ?>?showsource=php" class="alt">view php source</a>
</div>
<?php


///////////////////////////////////////////////////////////////////////////////
// recent posts listing
//
$limit="limit 10";
if (isset($_REQUEST["list"]))
{
    if ($_REQUEST["list"]=="all")
        $limit="";
    else
        $limit="limit ".intval($_REQUEST["list"]);
}

?>
<div id="menu">
<h1>Recent Posts</h1>
<ul>
<?php  

$db->query("select pid,poster,unix_timestamp()-unix_timestamp(posted) as age, ".
    "date_format(posted, '%a %D %b %H:%i') as postdate ".
    "from pastebin order by posted desc, pid desc $limit");
while ($db->next_record())
{
    $age=$db->f("age");
    $days=floor($age/(3600*24));
    $hours=floor($age/3600);
    $minutes=floor($age/60);
    $seconds=$age;
    
    if ($days>1)
        $age="$days days ago";
    elseif ($hours>0)
        $age="$hours hr".(($hours>1)?"s":"")." ago";
    elseif ($minutes>0)
        $age="$minutes min".(($minutes>1)?"s":"")." ago";
    else
        $age="$seconds sec".(($seconds>1)?"s":"")." ago";
    
    if ($_REQUEST["show"]==$db->f("pid"))
        $cls=" class=\"highlight\"";
    else
        $cls="";
            
    echo "<li{$cls}><a href=\"".shorturl($db->f("pid"))."\">";
    echo $db->f("poster");
    echo "</a><br />\n";
    
    echo $age;
    echo "</li>\n\n";
}

echo "<li><a href=\"pastebin.php\">Make new post</a></li>\n";
echo "</ul>";
?>


<form method="get" action="http://www.php.net/search.php">
<h1>PHP Manual</h1>
<input type="text" size="9" name="pattern"/>
<input type="hidden" name="show" value="quickref"/>
<input type="submit" value="go"/>
</form>

<form method="get" action="http://www.mysql.com/doc/search.php">
<h1>MySQL Manual</h1>
<input type="text" size="9" name="q"/>
<input type="submit" value="go"/>
</form>

<p>
    <a href="http://validator.w3.org/check/referer"><img
        src="http://www.w3.org/Icons/valid-xhtml10"
        alt="Valid XHTML 1.0!" height="31" width="88" border="0"/></a>
</p>

</div>


<div id="content">

<?php

///////////////////////////////////////////////////////////////////////////////
// show processing errors
//
if (count($errors))
{
    echo "<h1>Errors</h1><ul>";
    foreach($errors as $err)
    {
        echo "<li>$err</li>";
    }
    echo "</ul>";
    echo "<hr />";
}

///////////////////////////////////////////////////////////////////////////////
// show a post
//
if (isset($_REQUEST["show"]))
{
    
    $db->query("select *,date_format(posted, '%a %D %b %H:%i') as postdate ".
        "from pastebin where pid='{$_REQUEST[show]}';");
    if ($db->next_record())
    {
        //show a quick reference url, poster and parents
        echo "<h1>";
        //echo shorturl($db->f("pid"))."<br />";
        echo "Posted by ".$db->f("poster");
        echo " ".$db->f("postdate");
                
        if ($db->f("parent_pid")>0)
        {
            $db2=new DB;
            $db2->query("select pid,poster, ".
                "date_format(posted, '%a %D %b %H:%i') as posted ".
                "from pastebin where pid=".$db->f("parent_pid"));
            if ($db2->next_record())
            {
                echo " (modification of posting from ";
                echo "<a class=\"alt\" href=\"".shorturl($db2->f("pid"))."\">";
                echo $db2->f("poster");
                echo "</a> ";
                
                //echo $db2->f("posted");
                echo ")";
            }

        }
        
        echo "</h1>";
        
        //get formatted code
        $code=highlight_syntax($db->f("code"));
        
        //build a line numbering string
        $lines="<code><font color=\"#999999\">";
        
        $codeline=explode("<br />", $code);
        $linecount = count($codeline);
        for($l=1; $l<=$linecount; $l++)
        {
            $lines.=sprintf("%03d&nbsp;<br />", $l);
        }
        $lines.="</font></code>";
        
        //output
        echo "<table><tr>";
        echo "<td valign=\"top\">$lines</td><td valign=\"top\">$code</td>";
        echo "</tr></table>";
        
        //store the code for later editing
        $editcode=$db->f("code");
        
        //any amendments?
        $count=0;
        $db->query("select pid,poster,".
            "date_format(posted, '%a %D %b %H:%i') as posted ".
            "from pastebin where parent_pid=".$_REQUEST['show'].
            " order by posted desc;");
        while ($db->next_record())
        {
            if ($count++ == 0)
                echo "<br /><b>The following amendments have been posted:</b><ul>";
                
            echo "<li>";
            
            echo "<a href=\"".shorturl($db->f("pid"))."\">";
            echo $db->f("poster");
            echo "</a> (";

            echo $db->f("posted");
            echo ")</li>";
        }

        if ($count)
            echo "</ul>";

        echo "<br /><br /><b>Submit a correction or amendment below. (<a href=\"pastebin.php\">click here to make a fresh posting)</a></b>";
        
    }
    else
    {
        echo "<b>Unknown post id, it may have been deleted</b><br />";
    }
}
else
{
    echo "<h1>New posting</h1>";
}


///////////////////////////////////////////////////////////////////////////////
// submission form
//
$poster=$_COOKIE["persistName"];
if (strlen($poster))
    $remember="checked=\"checked\"";
else
    $remember="";


?>
<form name="editor" method="post" action="<?php echo $GLOBALS["this_script"] ?>">
<input type="hidden" name="parent_pid" value="<?php echo $_REQUEST["show"] ?>"/>
<b>Name</b><br />
<input type="text" maxlength="64" size="20" name="poster" value="<?php echo $poster ?>" />
<input type="submit" name="paste" value="Send"/>
<br />
<input type="checkbox" name="remember" value="1" <?php echo $remember ?>/>Remember my name in a cookie
<br /><br />

<b>Code:</b> To ensure legibility, keep your code lines under 80 characters long.<br />
Include comments to indicate what you need feedback on.<br />
<textarea class="codeedit" name="code" cols="80" rows="10">
<?php echo $editcode ?>
</textarea>

</form>
  
</div>

</body>
</html>

