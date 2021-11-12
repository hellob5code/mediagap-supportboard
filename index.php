<?php

if (isset($_GET["recordid"]))   {$recordid=$_GET["recordid"];}


$URL = "https://supportboard.medigaplife.com/admin.php";
//$iframe = "http://23.117.248.114/agc/vicidial.php";
echo "<HTML>\n";
echo "<head>\n";
echo "<script language=\"Javascript\">\n";
echo "var url=\"$URL\";\n";
//echo "var iframe=\"$iframe\";\n";
//echo "document.getElementById(\"popupFrame\").src=url;\n";
echo "top.location.href=url;\n";
//echo "window.open(url);\n";
//echo "document.getElementById(\'iframeid\').src=document.getElementById(\'iframeid\').src;\n";
echo "</script>\n";
echo "</head>\n";
echo "<title>Forward";
echo "</title>\n";
echo "</HTML>\n";


?>
