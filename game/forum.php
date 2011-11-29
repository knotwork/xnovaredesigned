<?php

$page = '';

$text = explode("<div id=\"text\" >",implode("\n",file("http://sourceforge.net/p/galacticmilieu/discussion/")));
$text = explode("</div>",$text[1]);
$page = $text[0];

if($_GET['axah']){
	echo $page;
}else{
	$makepage = array();
	$makepage['title'] = 'PAGE TITLE';
	$makepage['content'] = $page;
	echo parsetemplate(gettemplate('board/main'),$makepage);
}

?>
