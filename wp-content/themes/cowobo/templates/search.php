<?php



echo '<form method="GET" action="'.get_bloginfo('url').'" class="searchform">';		
		
		echo '<ul class="horlist searchbar">';
			echo '<li><a id="searchmenu" href="?action=search">Search for ▼</a></li>';
			echo '<li><a id="sortmenu" href="?action=sort">Sort by ▼</a></li>';
			echo '<li><a id="layoutmenu" href="?action=layout">Layout ▼</a></li>';
			echo '<li><a id="addmenu" href="?action=add">Add New ▼</a></li>';
		echo '</ul>';
		
		echo '<div class="hide dropmenu searchmenu">';
			echo '<input type="text" class="searchfield" name="s" value="'.cowobo()->query->s.'" placeholder="Enter keywords and/or select categories below"/>';
			echo '<br/><input type="submit" class="button" value="Update"/>';
			if($querycats = cowobo()->query->cats) $selected = $querycats; 
			else $selected = array(get_cat_ID('Coders'), get_cat_ID('Jobs'));
			foreach( get_categories('parent=0&hide_empty=0&exclude='.get_cat_ID('Uncategorized')) as $cat ):
				if(in_array($cat->term_id, $selected)) $state = 'checked'; else $state='';
				echo '<span class="'.$state.'"><input type="checkbox" name="cats[]" value="'.$cat->term_id.'" '.$state.'>'.$cat->name.'</span>';
			endforeach;
		echo '</div>';
		
		$sorttypes = array(
			'rating'=>'Rating',
			'location'=>'Locations',
			'modified'=>'Date',
			'login'=>'Last Login',
			'category'=>'Category',
			'a-z'=>'Title A-Z',
			'z-a'=>'Title Z-A',
			'comment_count'=>'Replies',
			'rand'=>'Random',
		);
			
		echo '<div class="hide dropmenu sortmenu">';
			if( $querysort = cowobo()->query->sort ) $selected = $querysort; 
			else $selected = array( 'modified' );
			foreach( $sorttypes as $sortslug => $sortlabel ):
				if( in_array($sortslug, $selected) ) $state = 'checked'; else $state='';
				echo '<span class="'.$state.'"><input type="checkbox" name="sort[]" value="'.$sortslug.'" '.$state.'>'.$sortlabel.'</span>';			
			endforeach;
			echo '<br/><input type="submit" class="button" value="Sort"/>';
		echo '</div>';
		
		echo '<div class="hide dropmenu layoutmenu">';
				echo 'This functionality is coming soon..';
		echo '</div>';
		
		echo '<div class="hide dropmenu addmenu">';
			echo '<input type="text" class="smallfield" name="url"/>';
			echo '<select name="addnew" class="smallfield">';
				foreach( get_categories('parent=0&hide_empty=0&exclude='.get_cat_ID('Uncategorized')) as $cat ):
					if(in_array($cat->term_id, $selected)) $state = 'checked'; else $state='';
					echo '<option value="'.$cat->term_id.'" '.$state.'>'.$cat->name.'</option>';
				endforeach;
			echo '</select>';
			echo '<input type="submit" class="button" value="Add It!"/>';
		echo '</div>';
	
echo '</form>';	
