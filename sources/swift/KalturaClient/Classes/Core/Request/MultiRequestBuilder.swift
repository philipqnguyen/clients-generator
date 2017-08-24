// ===================================================================================================
//                           _  __     _ _
//                          | |/ /__ _| | |_ _  _ _ _ __ _
//                          | ' </ _` | |  _| || | '_/ _` |
//                          |_|\_\__,_|_|\__|\_,_|_| \__,_|
//
// This file is part of the Kaltura Collaborative Media Suite which allows users
// to do with audio, video, and animation what Wiki platfroms allow them to do with
// text.
//
// Copyright (C) 2006-2017  Kaltura Inc.
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
// @ignore
// ===================================================================================================

/**
 * This class was generated using exec.php
 * against an XML schema provided by Kaltura.
 *
 * MANUAL CHANGES TO THIS CLASS WILL BE OVERWRITTEN.
 */

import SwiftyJSON



public class MultiRequestBuilder: ArrayRequestBuilder<Any,BaseTokenizedObject,BaseTokenizedObject> {
    
    
    var requests = [RequestBuilderProtocol]()
    
    @discardableResult
    public override func add(request: RequestBuilderProtocol) -> Self  {
        var subRequest = request
        subRequest.index = requests.count
        self.requests.append(request)
        return self
    }
    
    public override func getUrlTail() -> String {
        return "/service/multirequest"
    }

    
    override public func build(_ client: Client) -> Request {
        
        for (index, request)  in self.requests.enumerated() {
            let indexKey = String(index+1)
            request.setBody(key: "action", value: request.action)
            request.setBody(key: "service", value: request.service)
            setBody(key: indexKey, value: request.params)
            for (key, requestFile) in request.files {
                setFile(key: "\(indexKey):\(key)", value: requestFile)
            }
        }
        
        return super.build(client)
    }
    
    public override func onComplete(_ response: Response) -> Void {

        // calling on complete of each request
        var allResponse: [JSON] = []
        if let result = response.data?["result"], let responses = result.array {
            allResponse = responses
        }
        else if let responses = response.data?.array{
            allResponse = responses
        }
        var allParsedResponse = [Any]()
        for (index, request) in self.requests.enumerated() {
            let singelResponse = allResponse[index]
            let response = Response(data: singelResponse, error: response.error)
            let parsed = request.parse(response)
            request.complete(data: parsed.data, exception: parsed.exception)
            allParsedResponse.append(parsed.data ?? parsed.exception ?? ApiException())
        }
        
        if let block = completion {
            block(allParsedResponse,response.error)
        }
        
        return
    }
    
    public override func buildParamsAsData(params: [String: Any]) -> Data?
    {
        return self.params.sortedJsonData()
    }
    
    
    //[user,childrebs,1,name]
    public func link(tokenFromRespose: BaseTokenizedObject, tokenToRequest: BaseTokenizedObject) -> Self{
        
        
        
        var resultCollection: Any? = nil
        let request = self.requests[tokenFromRespose.requestId]
        let requestTokens = tokenFromRespose.tokens
        var next: Any? = request.params
        var collectionArray = [Any?].init(repeating: nil, count: requestTokens.count)
        
        for (index,token) in requestTokens.enumerated() {
            if ( index == requestTokens.count - 1) {
                collectionArray[index] = tokenToRequest.result
                
            }else{
                if let number = Int(token) {
                    if var array = next as? [Any] {
                        collectionArray[index] = array
                        next = array[number]
                    }else{
                        collectionArray[index] = self.getDummyInstance(token: token)
                        next = nil
                    }
                }else {
                    if var dicionary = next as? [String: Any] {
                        collectionArray[index] = dicionary
                        next = dicionary[token]
                    }else{
                        collectionArray[index] = [token:""]
                        next = nil
                    }
                }
                
            }
        }
        
        for (index,collection) in collectionArray.reversed().enumerated() {
            let reversedToken = requestTokens[requestTokens.count - index - 1]
            
            if var dict =  collection as? [String: Any]{
                dict[reversedToken] = resultCollection
                resultCollection = dict
            }
            else if var array =  collection as? [Any], let number = Int(reversedToken) {
                array[number] = resultCollection ?? ""
                resultCollection = array
            }
            else{
                resultCollection = collection
            }
        }
        
        request.setBody(key: requestTokens.first!, value: resultCollection)
        return self
    }
    
    func getDummyInstance(token: String) -> Any {
        if let number = Int(token) {
            return [Any].init(repeating: "", count: number)
        }else{
            return [String:Any]()
        }
    }
    
}



extension Dictionary where Key == String {
    
    func sortedJsonData() -> Data? {
        
        var result = ""
        let prefix = "{"
        let suffix = "}"
        
        
        result.append(prefix)
        let sortedKeys = self.keys.sorted()
        for key in sortedKeys {
            
            let jsonObject = self[key]!
            var jsonObjectString: String? = nil
            if ( JSONSerialization.isValidJSONObject(jsonObject)){
                do {
                    let jsonData = try JSONSerialization.data(withJSONObject: jsonObject)
                    jsonObjectString = String(data: jsonData, encoding: String.Encoding.utf8)
                }catch{
                    
                }
            }else if let object = jsonObject as? String {
                jsonObjectString = "\"\(object)\""
            }else if let object = jsonObject as? Int {
                jsonObjectString = String(object)
            }
            
            
            if let value = jsonObjectString  {
                result.append("\"\(key)\":")
                result.append(value)
                result.append(",")
            }
        }
        
        result = String(result.characters.dropLast())
        result.append(suffix)
        
        let data = result.data(using: String.Encoding.utf8)
        return data
    }
}




