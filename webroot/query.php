<?PHP
//The description of the flags used in this file are being based on the
//DirFindFlags enum which is defined in OpenMetaverse/DirectoryManager.cs
//of the libopenmetaverse library.

//TODO:
//classifieds, events, land sales, and places all support region(?) type
//in search criteria in viewer.
//
//SearchTypeFlags -1 (Any)
//PlacesFlags: 128 (ForSale)
//
//ClassifiedFlags only has bit for Mature. It has nothing for Adult.
//EventFlags is 0 (PG), 1 (Mature), 2 (Adult)

include("databaseinfo.php");

$now = time();

//
// Search DB
//
mysql_connect ($DB_HOST, $DB_USER, $DB_PASSWORD);
mysql_select_db ($DB_NAME);

#
#  Copyright (c)Melanie Thielker (http://opensimulator.org/)
#

###################### No user serviceable parts below #####################

#
# The XMLRPC server object
#

$xmlrpc_server = xmlrpc_server_create();

#
# Places Query
#

xmlrpc_server_register_method($xmlrpc_server, "dir_places_query",
        "dir_places_query");

function dir_places_query($method_name, $params, $app_data)
{
    $req             = $params[0];

    $text             = $req['text'];
    $category         = $req['category'];
    $query_start     = $req['query_start'];

    $pieces = split(" ", $text);
    $text = join("%", $pieces);

    if ($text == "%%%")
    {
        $response_xml = xmlrpc_encode(array(
                'success'      => False,
                'errorMessage' => "Invalid search terms"
        ));

        print $response_xml;

        return;
    }

    if ($category != -1)
    {
        $result = mysql_query("SELECT * FROM parcels WHERE " .
                "(searchcategory = -1 or searchcategory = '" .
                mysql_escape_string($category) ."') AND (parcelname LIKE '%" .
                mysql_escape_string($text) . "%' OR description LIKE '%" .
                mysql_escape_string($text) . "%') ORDER BY " .
                "dwell DESC, parcelname" .
                " LIMIT ".(0+$query_start).",100");
    }
    else
    {
        $result = mysql_query("SELECT * FROM parcels WHERE " .
                "parcelname like '%" .
                mysql_escape_string($text) . "%' OR description LIKE '%" .
                mysql_escape_string($text) . "%' ORDER BY " .
                "dwell DESC, parcelname" .
                " LIMIT ".(0+$query_start).",100");
    }

    $data = array();
    while (($row = mysql_fetch_assoc($result)))
    {
        $data[] = array(
                "parcel_id" => $row["infouuid"],
                "name" => $row["parcelname"],
                "for_sale" => "False",
                "auction" => "False",
                "dwell" => $row["dwell"]);
    }
    $response_xml = xmlrpc_encode(array(
        'success'      => True,
        'errorMessage' => "",
        'data' => $data
    ));

    print $response_xml;
}

#
# Popular Place Query
#

xmlrpc_server_register_method($xmlrpc_server, "dir_popular_query",
        "dir_popular_query");

function dir_popular_query($method_name, $params, $app_data)
{
    $req    = $params[0];

    $flags    = $req['flags'];

    $terms    = array();

    if ($flags & 0x1000)	//PicturesOnly (1 << 12)
        $terms[] = "has_picture = 1";

    if ($flags & 0x0800)	//PgSimsOnly (1 << 11)
        $terms[] = "mature = 0";

    $where = "";
    if (count($terms) > 0)
        $where = " WHERE " . join(" AND ", $terms);

    $result = mysql_query("SELECT * FROM popularplaces" . $where);

    $data = array();
    while (($row = mysql_fetch_assoc($result)))
    {
        $data[] = array(
                "parcel_id" => $row["infoUUID"],
                "name" => $row["name"],
                "dwell" => $row["dwell"]);
    }

    $response_xml = xmlrpc_encode(array(
            'success'      => True,
            'errorMessage' => "",
            'data' => $data));

    print $response_xml;
}

#
# Land Query
#

xmlrpc_server_register_method($xmlrpc_server, "dir_land_query",
        "dir_land_query");

function dir_land_query($method_name, $params, $app_data)
{
    $req            = $params[0];

    $flags            = $req['flags'];
    $type            = $req['type'];
    $price            = $req['price'];
    $area            = $req['area'];
    $query_start    = $req['query_start'];

    $terms = array();
    $order = "lsq";		//PerMeterSort (1 << 17)
    if ($flags & 0x80000)	//NameSort (1 << 19)
        $order = "parcelname";
    if ($flags & 0x10000)	//PriceSort (1 << 16)
        $order = "saleprice";
    if ($flags & 0x40000)	//AreaSort (1 << 18)
        $order = "area";
    if (!($flags & 0x8000))	//SortAsc (1 << 15)
        $order .= " DESC";

    if ($flags & 0x100000)	//LimitByPrice (1 << 20)
        $terms[] = "saleprice <= '" . mysql_escape_string($price) . "'";

    if ($flags & 0x200000)	//LimitByArea (1 << 21)
        $terms[] = "area >= '" . mysql_escape_string($area) . "'";

    if (($type & 26) == 2) // Auction (from SearchTypeFlags enum)
    {
        $response_xml = xmlrpc_encode(array(
                'success' => False,
                'errorMessage' => "No auctions listed"));

        print $response_xml;

        return;
    }

    if (($type & 24) == 8)	//Mainland (24=0x18 [bits 3 & 4])
        $terms[] = "parentestate = 1";
    if (($type & 24) == 16)	//Estate (24=0x18 [bits 3 & 4])
        $terms[] = "parentestate <> 1";

    if ($flags & 0x0800)	//PgSimsOnly (1 << 11)
        $terms[] = "mature = 'false'";
    if ($flags & 0x4000)	//MatureSimsOnly (1 << 14)
        $terms[] = "mature = 'true'";

    $where = "";
    if (count($terms) > 0)
        $where = " WHERE " . join(" AND ", $terms);

    $sql = "SELECT *, saleprice/area AS lsq FROM parcelsales" . $where .
                " ORDER BY " . $order . " LIMIT " .
                mysql_escape_string($query_start) . ",101";

    $result = mysql_query($sql);

    $data = array();
    while (($row = mysql_fetch_assoc($result)))
    {
        $data[] = array(
                "parcel_id" => $row["infoUUID"],
                "name" => $row["parcelname"],
                "auction" => "false",
                "for_sale" => "true",
                "sale_price" => $row["saleprice"],
                "landing_point" => $row["landingpoint"],
                "region_UUID" => $row["regionUUID"],
                "area" => $row["area"]);
    }

    $response_xml = xmlrpc_encode(array(
            'success'      => True,
            'errorMessage' => "",
            'data' => $data));

    print $response_xml;
}

#
# Events Query
#

xmlrpc_server_register_method($xmlrpc_server, "dir_events_query",
        "dir_events_query");

function dir_events_query($method_name, $params, $app_data)
{
    $req            = $params[0];

    $text            = $req['text'];
    $flags            = $req['flags'];
    $query_start    = $req['query_start'];

    if ($text == "%%%")
    {
        $response_xml = xmlrpc_encode(array(
                'success'      => False,
                'errorMessage' => "Invalid search terms"
        ));

        print $response_xml;

        return;
    }

    $pieces = explode("|", $text);
    
    $day        =    $pieces[0];
    $category    =    $pieces[1];

    //Setting a variable for NOW
    $now        =    time();
    
    $terms = array();

    if ($day == "u") $terms[] = "dateUTC > ".$now."";

    if ($category <> 0) $terms[] = "category = ".$category."";
    
    if ($flags & 0x2000)	//PgEventsOnly (1 << 13)
        $terms[] = "mature = 'false'";

    $where = "";

    if (count($terms) > 0)
    $where = " WHERE " . join(" AND ", $terms);

    $sql = "SELECT * FROM events". $where.
           " LIMIT " . mysql_escape_string($query_start) . ",101";

    $result = mysql_query($sql);

    $data = array();

    while (($row = mysql_fetch_assoc($result)))
    {
        $date = strftime("%m/%d %I:%M %p",$row["dateUTC"]);
        
        $data[] = array(
                "owner_id" => $row["owneruuid"],
                "name" => $row["name"],
                "event_id" => $row["eventid"],
                "date" => $date,
                "unix_time" => $row["dateUTC"],
                "event_flags" => $row["eventflags"]);
    }

    $response_xml = xmlrpc_encode(array(
            'success'      => True,
            'errorMessage' => "",
            'data' => $data));

    print $response_xml;
}

#
# Classifieds Query
#

xmlrpc_server_register_method($xmlrpc_server, "dir_classified_query",
        "dir_classified_query");

function dir_classified_query ($method_name, $params, $app_data)
{
    $req            = $params[0];

    $text             = $req['text'];
    $flags            = $req['flags'];
    $category         = $req['category'];
    $query_start     = $req['query_start'];

    if ($text == "%%%")
    {
        $response_xml = xmlrpc_encode(array(
                'success'      => False,
                'errorMessage' => "Invalid search terms"
        ));

        print $response_xml;

        return;
    }

    if ($category <> 0) $terms[] = "category = ".$category."";
    
    $where = "";

    if (count($terms) > 0)
    $where = " WHERE " . join(" AND ", $terms);

    $sql = "SELECT * FROM classifieds". $where.
           " LIMIT " . mysql_escape_string($query_start) . ",101";

    $result = mysql_query($sql);

    $data = array();
    while (($row = mysql_fetch_assoc($result)))
    {
        $data[] = array(
                "classifiedid" => $row["classifieduuid"],
                "name" => $row["name"],
                "classifiedflags" => $row["classifiedflags"],
                "creation_date" => $row["creationdate"],
                "expiration_date" => $row["expirationdate"],
                "priceforlisting" => $row["priceforlisting"]);
    }

    $response_xml = xmlrpc_encode(array(
            'success'      => True,
            'errorMessage' => "",
            'data' => $data));

    print $response_xml;
}

#
# Events Info Query
#

xmlrpc_server_register_method($xmlrpc_server, "event_info_query",
        "event_info_query");

function event_info_query($method_name, $params, $app_data)
{
    $req        = $params[0];

    $eventID    = $req['eventID'];

    $sql =  "SELECT * FROM events WHERE eventID = " .
            mysql_escape_string($eventID); 

    $result = mysql_query($sql);

    $data = array();
    while (($row = mysql_fetch_assoc($result)))
    {
        $date = strftime("%G-%m-%d %H:%M:%S",$row["dateUTC"]);

        if ($row['category'] == 18)    $category = "Discussion";
        if ($row['category'] == 19)    $category = "Sports";
        if ($row['category'] == 20)    $category = "Live Music";
        if ($row['category'] == 22)    $category = "Commercial";
        if ($row['category'] == 23)    $category = "Nightlife/Entertainment";
        if ($row['category'] == 24) $category = "Games/Contests";
        if ($row['category'] == 25)    $category = "Pageants";
        if ($row['category'] == 26)    $category = "Education";
        if ($row['category'] == 27)    $category = "Arts and Culture";
        if ($row['category'] == 28) $category = "Charity/Support Groups";
        if ($row['category'] == 29)    $category = "Miscellaneous";

        $data[] = array(
                "event_id" => $row["eventid"],
                "creator" => $row["creatoruuid"],
                "name" => $row["name"],
                "category" => $category,
                "description" => $row["description"],
                "date" => $date,
                "dateUTC" => $row["dateUTC"],
                "duration" => $row["duration"],
                "covercharge" => $row["covercharge"],
                "coveramount" => $row["coveramount"],
                "simname" => $row["simname"],
                "globalposition" => $row["globalPos"],
                "eventflags" => $row["eventflags"]);
    }

    $response_xml = xmlrpc_encode(array(
            'success'      => True,
            'errorMessage' => "",
            'data' => $data));

    print $response_xml;
}

#
# Classifieds Info Query
#

xmlrpc_server_register_method($xmlrpc_server, "classifieds_info_query",
        "classifieds_info_query");

function classifieds_info_query($method_name, $params, $app_data)
{
    $req            = $params[0];

    $classifiedID    = $req['classifiedID'];

    $sql =  "SELECT * FROM classifieds WHERE classifieduuid = '" .
            mysql_escape_string($classifiedID). "'"; 

    $result = mysql_query($sql);

    $data = array();
    while (($row = mysql_fetch_assoc($result)))
    {
        $data[] = array(
                "classifieduuid" => $row["classifieduuid"],
                "creatoruuid" => $row["creatoruuid"],
                "creationdate" => $row["creationdate"],
                "expirationdate" => $row["expirationdate"],
                "category" => $row["category"],
                "name" => $row["name"],
                "description" => $row["description"],
                "parceluuid" => $row["parceluuid"],
                "parentestate" => $row["parentestate"],
                "snapshotuuid" => $row["snapshotuuid"],
                "simname" => $row["simname"],
                "posglobal" => $row["posglobal"],
                "parcelname" => $row["parcelname"],
                "classifiedflags" => $row["classifiedflags"],
                "priceforlisting" => $row["priceforlisting"]);
    }

    $response_xml = xmlrpc_encode(array(
            'success'      => True,
            'errorMessage' => "",
            'data' => $data));

    print $response_xml;
}
    
#
# Process the request
#

$request_xml = $HTTP_RAW_POST_DATA;
xmlrpc_server_call_method($xmlrpc_server, $request_xml, '');
xmlrpc_server_destroy($xmlrpc_server);
?>
