<ul class="nav navbar-nav">
    <li class="dropdown">
        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Gerrit <span class="caret"></span></a>
        <ul class="dropdown-menu" role="menu">
            <li><a href="#">All</a></li>
            <li><a href="#"><i class="fa fa-fw fa-check-square-o"></i> Has verifications</a></li>
            <li><a href="#"><i class="fa fa-fw fa-plus-square-o"></i> Needs Verification</a></li>
            <li><a href="#"><i class="fa fa-fw fa-plus-square-o"></i> Needs Review</a</li>
            <li><a href="#"><i class="fa fa-fw fa-refresh"></i> Rebase necessary</a></li>
        </ul>
    </li>
    <li class="dropdown">
        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Sprints <span class="caret"></span></a>
        <ul class="dropdown-menu" role="menu">
            <li><a href="#"><i class="fa fa-fw fa-table"></i> Sprint Boards</a></li>
            <li><a href="#"><i class="fa fa-fw fa-list"></i> Sprint Lists</a></li>
            <li><a href="#"><i class="fa fa-fw fa-th-list"></i> Issue Management</a></li>
        </ul>
    </li>
    <li class="dropdown">
        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Utility <span class="caret"></span></a>
        <ul class="dropdown-menu" role="menu">
            <li><a href="#"><i class="fa fa-fw fa-file-text-o"></i> ReST Helper</a</li>
            <li><a href="#"><i class="fa fa-fw fa-calendar"></i> Team Calendar</a></li>
            <li><a href="#"><i class="fa fa-fw fa-slack"></i> Slack Invite</a></li>
        </ul>
    </li>
    <li class="dropdown">
        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Help <span class="caret"></span></a>
        <ul class="dropdown-menu" role="menu">
            <li><a href="#"><i class="fa fa-fw fa-question-circle"></i> Graphs</a></li>
        </ul>
    </li>
</ul>

<form action="search" controller="standard" method="get" class="navbar-form navbar-right">
    <div class="form-group">
        <div class="input-group">
            <input type="text" name="query" placeholder="Issue ID or Searchwords" class="form-control" value="" />
			<span class="input-group-btn">
				<button type="submit" class="btn btn-success"><i class="fa fa-fw fa-search"></i></button>
			</span>
        </div>
    </div>
</form>

